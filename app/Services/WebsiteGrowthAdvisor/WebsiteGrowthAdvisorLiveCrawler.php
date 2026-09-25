<?php

namespace App\Services\WebsiteGrowthAdvisor;

use App\Models\Site;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Read-only, bounded crawl used as live evidence by Growth Advisor.
 *
 * This deliberately does not reuse the Knowledge crawler: that crawler
 * persists Page records, dispatches image jobs and feeds the index. An
 * analysis must be safe to rerun and must not mutate the tenant Knowledge.
 */
final class WebsiteGrowthAdvisorLiveCrawler
{
    private const DEFAULT_MAX_PAGES = 8;
    private const MAX_MAX_PAGES = 20;
    private const DEFAULT_MAX_DEPTH = 1;
    private const MAX_MAX_DEPTH = 3;
    private const REQUEST_TIMEOUT = 12;
    private const MAX_DURATION_SECONDS = 45;
    private const MAX_HTML_BYTES = 1_500_000;
    private const MAX_TEXT_LENGTH = 8_000;

    public function __construct(private readonly ?HttpClientInterface $http = null)
    {
    }

    /**
     * @param array<string, mixed> $options
     * @param array<int, string> $fallbackTargets
     * @return array<string, mixed>
     */
    public function crawl(Site $site, array $options = [], array $fallbackTargets = []): array
    {
        $scope = in_array(($options['scope'] ?? 'page'), ['page', 'site'], true)
            ? (string) $options['scope']
            : 'page';
        $maxPages = max(1, min(self::MAX_MAX_PAGES, (int) ($options['max_pages'] ?? self::DEFAULT_MAX_PAGES)));
        $maxDepth = max(0, min(self::MAX_MAX_DEPTH, (int) ($options['max_depth'] ?? self::DEFAULT_MAX_DEPTH)));
        $baseUrl = $this->normalizeUrl((string) ($site->url ?? ''));
        $baseHost = $baseUrl ? strtolower((string) parse_url($baseUrl, PHP_URL_HOST)) : null;
        $errors = [];

        if (! $baseUrl || ! $baseHost || ! $this->isSafePublicUrl($baseUrl, $errors)) {
            return $this->emptyResult($scope, $maxPages, $maxDepth, 'Site URL invalide ou non accessible publiquement.', $errors);
        }

        $configuredTargets = is_array($options['urls'] ?? null) ? $options['urls'] : [];
        // Explicit URLs are authoritative. Journey-derived targets are only
        // a fallback when the tenant did not configure a target list.
        $rawTargets = $configuredTargets !== []
            ? array_values(array_unique($configuredTargets))
            : ($scope === 'site' ? [$baseUrl] : array_values(array_unique($fallbackTargets)));
        $targets = [];
        foreach ($rawTargets as $rawTarget) {
            if (! is_string($rawTarget) || trim($rawTarget) === '') continue;
            $target = $this->normalizeUrl($this->resolveUrl(trim($rawTarget), $baseUrl) ?? '');
            if (! $target || ! $this->isSameHost($target, $baseHost)) {
                $errors[] = 'Cible ignorée car elle est invalide ou hors du domaine du site : '.Str::limit((string) $rawTarget, 240, '…');
                continue;
            }
            if ($this->isExcludedOrNotIncluded($target, $site)) continue;
            $targets[] = $target;
        }

        if ($targets === []) $targets[] = $baseUrl;
        if ($scope === 'page') $targets = array_slice($targets, 0, $maxPages);

        $robots = $this->loadRobotsPolicy($baseUrl, $baseHost, $errors);
        $queue = [];
        $queued = [];
        foreach ($targets as $target) {
            $queue[] = ['url' => $target, 'depth' => 0];
            $queued[$target] = true;
        }

        $pages = [];
        $startedAt = microtime(true);
        while ($queue !== [] && count($pages) < $maxPages) {
            if ((microtime(true) - $startedAt) >= self::MAX_DURATION_SECONDS) {
                $errors[] = 'Limite de durée du crawl live atteinte.';
                break;
            }

            $item = array_shift($queue);
            $url = (string) ($item['url'] ?? '');
            $depth = (int) ($item['depth'] ?? 0);
            if ($url === '' || $depth > $maxDepth) continue;
            if (! $this->robotsAllowed($url, $robots)) {
                $errors[] = 'Page ignorée par robots.txt : '.$url;
                continue;
            }

            $fetched = $this->fetchPage($url, $baseHost, $errors);
            if ($fetched['page'] === null) continue;
            $page = $fetched['page'];
            $page['depth'] = $depth;
            $pages[] = $page;

            if ($scope !== 'site' || $depth >= $maxDepth || count($pages) >= $maxPages) continue;
            foreach ($page['discovered_links'] ?? [] as $link) {
                $next = is_string($link) ? $link : (string) ($link['url'] ?? '');
                if ($next === '' || isset($queued[$next]) || ! $this->isSameHost($next, $baseHost)) continue;
                if ($this->isExcludedOrNotIncluded($next, $site)) continue;
                $queued[$next] = true;
                $queue[] = ['url' => $next, 'depth' => $depth + 1];
            }
        }

        foreach ($pages as &$page) unset($page['discovered_links']);
        unset($page);

        $status = $pages === []
            ? 'unavailable'
            : ($errors === [] ? 'ready' : 'partial');

        return [
            'status' => $status,
            'scope' => $scope,
            'render_mode' => 'server_html',
            'javascript_rendered' => false,
            'root_url' => $baseUrl,
            'requested_urls' => $targets,
            'pages' => array_values($pages),
            'errors' => array_values(array_unique(array_slice($errors, 0, 20))),
            'limits' => [
                'max_pages' => $maxPages,
                'max_depth' => $maxDepth,
                'max_duration_seconds' => self::MAX_DURATION_SECONDS,
                'read_only' => true,
                'knowledge_index_updated' => false,
            ],
        ];
    }

    /** @param array<int, string> $errors @return array<string, mixed> */
    private function emptyResult(string $scope, int $maxPages, int $maxDepth, string $message, array $errors): array
    {
        $errors[] = $message;
        return [
            'status' => 'unavailable',
            'scope' => $scope,
            'render_mode' => 'server_html',
            'javascript_rendered' => false,
            'root_url' => null,
            'requested_urls' => [],
            'pages' => [],
            'errors' => array_values(array_unique($errors)),
            'limits' => [
                'max_pages' => $maxPages,
                'max_depth' => $maxDepth,
                'max_duration_seconds' => self::MAX_DURATION_SECONDS,
                'read_only' => true,
                'knowledge_index_updated' => false,
            ],
        ];
    }

    /** @param array<int, string> $errors */
    private function fetchPage(string $url, string $baseHost, array &$errors): array
    {
        if (! $this->isSafePublicUrl($url, $errors)) return ['page' => null];

        try {
            $response = ($this->http ?: HttpClient::create())
                ->request('GET', $url, [
                    'timeout' => self::REQUEST_TIMEOUT,
                    'max_redirects' => 3,
                    'headers' => [
                        'User-Agent' => 'ELChat-WebsiteGrowthAdvisor/1.0',
                        'Accept' => 'text/html,application/xhtml+xml',
                    ],
                ]);
            $statusCode = $response->getStatusCode();
            $finalUrl = $this->normalizeUrl((string) ($response->getInfo('url') ?: $url)) ?: $url;
            if (! $this->isSameHost($finalUrl, $baseHost)) {
                $errors[] = 'Redirection hors du domaine refusée : '.$url;
                return ['page' => null];
            }
            if ($statusCode >= 400) {
                $errors[] = 'Réponse HTTP '.$statusCode.' pour '.$url;
                return ['page' => null];
            }

            $headers = $response->getHeaders(false);
            $contentType = strtolower((string) ($headers['content-type'][0] ?? ''));
            if ($contentType !== '' && ! str_contains($contentType, 'text/html') && ! str_contains($contentType, 'application/xhtml')) {
                $errors[] = 'Ressource non HTML ignorée : '.$url;
                return ['page' => null];
            }

            $html = $response->getContent(false);
            if (! is_string($html) || trim($html) === '') {
                $errors[] = 'Réponse HTML vide pour '.$url;
                return ['page' => null];
            }
            if (strlen($html) > self::MAX_HTML_BYTES) $html = substr($html, 0, self::MAX_HTML_BYTES);

            return ['page' => $this->extractPage($html, $finalUrl, $statusCode, $baseHost)];
        } catch (Throwable $exception) {
            $errors[] = 'Échec de lecture de '.$url.' : '.Str::limit($exception->getMessage(), 240, '…');
            return ['page' => null];
        }
    }

    /** @return array<string, mixed> */
    private function extractPage(string $html, string $url, int $statusCode, string $baseHost): array
    {
        $crawler = new Crawler($html, $url);
        $title = $crawler->filter('title')->count() ? $this->text($crawler->filter('title')->text(), 300) : null;
        $description = $crawler->filter('meta[name="description"]')->count()
            ? $this->text($crawler->filter('meta[name="description"]')->attr('content'), 500)
            : null;
        $canonical = null;
        if ($crawler->filter('link[rel="canonical"]')->count()) {
            $canonical = $this->normalizeUrl($this->resolveUrl((string) $crawler->filter('link[rel="canonical"]')->attr('href'), $url) ?? '');
        }

        $headings = [];
        $crawler->filter('h1,h2,h3')->each(function (Crawler $node) use (&$headings): void {
            $value = $this->text($node->text(), 500);
            if ($value) $headings[] = ['level' => (int) substr($node->nodeName(), 1), 'text' => $value];
        });

        $sections = [];
        $current = null;
        $crawler->filter('h1,h2,h3,p,li')->each(function (Crawler $node) use (&$sections, &$current, $url): void {
            $tag = strtolower($node->nodeName());
            $value = $this->text($node->text(), 900);
            if (! $value) return;
            if (in_array($tag, ['h1', 'h2', 'h3'], true)) {
                if ($current !== null) $sections[] = $current;
                $current = [
                    'heading' => $value,
                    'level' => (int) substr($tag, 1),
                    'page_url' => $url,
                    'selector' => $this->selectorPath($node),
                    'selector_hint' => $this->selectorHint($node),
                    'content' => [],
                ];
                return;
            }
            if ($current === null) $current = ['heading' => null, 'level' => null, 'content' => []];
            if (count($current['content']) < 8) $current['content'][] = $value;
        });
        if ($current !== null) $sections[] = $current;
        $sections = array_slice($sections, 0, 40);

        $links = [];
        $discovered = [];
        $crawler->filter('a[href]')->each(function (Crawler $node) use (&$links, &$discovered, $url, $baseHost): void {
            if (count($links) >= 100) return;
            $href = trim((string) $node->attr('href'));
            $resolved = $this->normalizeUrl($this->resolveUrl($href, $url) ?? '');
            if (! $resolved) return;
            $internal = $this->isSameHost($resolved, $baseHost);
            $entry = [
                'text' => $this->text($node->text(), 240),
                'url' => $resolved,
                'page_url' => $url,
                'selector' => $this->selectorPath($node),
                'selector_hint' => $this->selectorHint($node),
                'internal' => $internal,
                'rel' => $this->text($node->attr('rel'), 100),
                'target' => $this->text($node->attr('target'), 40),
            ];
            $links[] = $entry;
            if ($internal) $discovered[$resolved] = true;
        });

        $ctas = [];
        $crawler->filter('a[href],button,input[type="submit"],input[type="button"]')->each(function (Crawler $node) use (&$ctas, $url): void {
            if (count($ctas) >= 50) return;
            $tag = strtolower($node->nodeName());
            $text = $this->text($tag === 'input' ? $node->attr('value') : $node->text(), 240);
            $class = strtolower(trim((string) $node->attr('class')));
            $id = strtolower(trim((string) $node->attr('id')));
            $signal = Str::contains($class.' '.$id, ['cta', 'primary', 'conversion', 'contact', 'quote', 'demo'])
                || (bool) preg_match('/\b(contact|devis|demander|réserver|reserver|acheter|commencer|essayer|demo|d[eé]mo|en savoir|voir|t[eé]l[eé]charger)\b/iu', (string) $text);
            if (! $signal && $tag !== 'button' && ! in_array($node->attr('type'), ['submit', 'button'], true)) return;
            $target = $tag === 'a' ? $this->normalizeUrl($this->resolveUrl((string) $node->attr('href'), $url) ?? '') : null;
            $ctas[] = [
                'tag' => $tag,
                'text' => $text,
                'url' => $target,
                'page_url' => $url,
                'selector' => $this->selectorPath($node),
                'selector_hint' => $this->selectorHint($node),
                'element_type' => $tag === 'a' ? 'link' : ($tag === 'button' ? 'button' : 'submit_control'),
                'current_text' => $text,
                'current_href' => $target,
                'supported_operations' => $tag === 'a'
                    ? ['replace_text', 'replace_href', 'replace_text_and_href', 'remove']
                    : ($tag === 'button' ? ['replace_text', 'attach_event', 'remove'] : ['replace_label', 'attach_event', 'remove']),
                'id' => $this->text($node->attr('id'), 100),
                'class' => $this->text($node->attr('class'), 180),
                'type' => $this->text($node->attr('type'), 40),
            ];
        });

        $forms = [];
        $crawler->filter('form')->each(function (Crawler $form) use (&$forms, $url): void {
            if (count($forms) >= 20) return;
            $fields = [];
            $form->filter('input,select,textarea')->each(function (Crawler $field) use (&$fields): void {
                if (count($fields) >= 40) return;
                $fields[] = [
                    'tag' => strtolower($field->nodeName()),
                    'name' => $this->text($field->attr('name'), 120),
                    'type' => $this->text($field->attr('type'), 40),
                    'required' => $field->attr('required') !== null,
                ];
            });
            $forms[] = [
                'page_url' => $url,
                'action' => $this->normalizeUrl($this->resolveUrl((string) $form->attr('action'), $url) ?? '') ?: $url,
                'method' => strtoupper((string) ($form->attr('method') ?: 'GET')),
                'selector' => $this->selectorPath($form),
                'selector_hint' => $this->selectorHint($form),
                'element_type' => 'form',
                'supported_operations' => ['add_field', 'replace_action', 'replace_label', 'attach_event'],
                'fields' => $fields,
            ];
        });

        $schema = [];
        $crawler->filter('script[type="application/ld+json"]')->each(function (Crawler $node) use (&$schema): void {
            if (count($schema) >= 20) return;
            $decoded = json_decode((string) $node->text(), true);
            if (! is_array($decoded)) return;
            $schema[] = [
                '@type' => $decoded['@type'] ?? null,
                'name' => $this->text($decoded['name'] ?? null, 300),
                'url' => $this->text($decoded['url'] ?? null, 500),
            ];
        });

        $bodyText = $crawler->filter('body')->count() ? $crawler->filter('body')->text(' ') : $crawler->text(' ');
        return [
            'url' => $url,
            'status_code' => $statusCode,
            'render_mode' => 'server_html',
            'javascript_rendered' => false,
            'title' => $title,
            'meta_description' => $description,
            'canonical' => $canonical,
            'language' => $this->text($crawler->filter('html')->attr('lang'), 20),
            'headings' => array_slice($headings, 0, 50),
            'sections' => $sections,
            'links' => $links,
            'ctas' => $ctas,
            'forms' => $forms,
            'structured_data' => $schema,
            'body_text' => $this->text($bodyText, self::MAX_TEXT_LENGTH),
            'word_count' => str_word_count(strip_tags($bodyText)),
            'fetched_at' => now()->toISOString(),
            'discovered_links' => array_keys($discovered),
        ];
    }

    /** @param array<int, string> $errors */
    private function isSafePublicUrl(string $url, array &$errors): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $port = $parts['port'] ?? null;
        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || ($port !== null && ! in_array((int) $port, [80, 443], true))) {
            $errors[] = 'URL non autorisée : '.$url;
            return false;
        }
        if (in_array($host, ['localhost', 'localhost.localdomain'], true) || str_ends_with($host, '.local')) {
            $errors[] = 'Hôte interne refusé : '.$host;
            return false;
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : @gethostbynamel($host);
        if (! is_array($ips) || $ips === []) {
            $errors[] = 'Résolution DNS impossible : '.$host;
            return false;
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                $errors[] = 'Adresse privée ou réservée refusée : '.$host;
                return false;
            }
        }
        return true;
    }

    /** @param array<int, string> $errors @return array<int, string> */
    private function loadRobotsPolicy(string $baseUrl, string $baseHost, array &$errors): array
    {
        $robotsUrl = rtrim($baseUrl, '/').'/robots.txt';
        try {
            if (! $this->isSafePublicUrl($robotsUrl, $errors)) return [];
            $response = ($this->http ?: HttpClient::create())->request('GET', $robotsUrl, [
                'timeout' => 5,
                'max_redirects' => 2,
                'headers' => ['User-Agent' => 'ELChat-WebsiteGrowthAdvisor/1.0', 'Accept' => 'text/plain'],
            ]);
            $final = $this->normalizeUrl((string) ($response->getInfo('url') ?: $robotsUrl)) ?: $robotsUrl;
            if (! $this->isSameHost($final, $baseHost) || $response->getStatusCode() >= 400) return [];
            $body = $response->getContent(false);
            return $this->parseRobots((string) $body);
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<int, string> */
    private function parseRobots(string $body): array
    {
        $rules = [];
        $active = false;
        foreach (preg_split('/\R/u', $body) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*/', '', $line));
            if ($line === '' || ! str_contains($line, ':')) continue;
            [$directive, $value] = array_map('trim', explode(':', $line, 2));
            $directive = strtolower($directive);
            if ($directive === 'user-agent') {
                $active = strtolower($value) === '*' || str_contains(strtolower($value), 'elchat');
            } elseif ($active && $directive === 'disallow' && $value !== '') {
                $rules[] = $value;
            }
        }
        return $rules;
    }

    /** @param array<int, string> $rules */
    private function robotsAllowed(string $url, array $rules): bool
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        foreach ($rules as $rule) {
            $rule = rtrim($rule, '*');
            if ($rule !== '' && str_starts_with($path, $rule)) return false;
        }
        return true;
    }

    private function isExcludedOrNotIncluded(string $url, Site $site): bool
    {
        foreach (['exclude_pages' => true, 'include_pages' => false] as $property => $exclude) {
            $patterns = is_array($site->{$property} ?? null) ? $site->{$property} : [];
            if ($patterns === []) continue;
            $matched = false;
            foreach ($patterns as $pattern) {
                if (! is_string($pattern) || trim($pattern) === '') continue;
                $pattern = trim($pattern);
                $candidate = $pattern[0] === '/' ? (string) parse_url($url, PHP_URL_PATH) : $url;
                $pattern = $pattern[0] === '/' ? rtrim($pattern, '/') : rtrim($pattern, '/');
                $candidate = rtrim($candidate, '/');
                $regex = '#^'.str_replace('\\*', '.*', preg_quote($pattern, '#')).'$#i';
                if (preg_match($regex, $candidate)) {
                    $matched = true;
                    break;
                }
            }
            if ($exclude && $matched) return true;
            if (! $exclude && ! $matched) return true;
        }
        return false;
    }

    private function isSameHost(string $url, string $baseHost): bool
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST)) === strtolower($baseHost);
    }

    private function normalizeUrl(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])) return null;
        $path = (string) ($parts['path'] ?? '/');
        if ($path === '') $path = '/';
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        foreach (array_keys($query) as $key) {
            if (preg_match('/^(utm_|gclid|fbclid|msclkid)/i', (string) $key)) unset($query[$key]);
        }
        ksort($query);
        $result = strtolower((string) $parts['scheme']).'://'.strtolower((string) $parts['host']);
        if (isset($parts['port'])) $result .= ':'.(int) $parts['port'];
        $result .= '/'.ltrim($path, '/');
        if ($query !== []) $result .= '?'.http_build_query($query);
        return rtrim($result, '/') ?: $result;
    }

    private function resolveUrl(string $raw, string $baseUrl): ?string
    {
        $raw = trim($raw);
        if ($raw === '' || preg_match('/^(#|mailto:|tel:|javascript:|data:)/i', $raw)) return null;
        if (str_starts_with($raw, '//')) {
            $raw = (string) parse_url($baseUrl, PHP_URL_SCHEME).':'.$raw;
        }
        if (parse_url($raw, PHP_URL_SCHEME)) return $raw;
        $base = parse_url($baseUrl);
        if (! is_array($base) || empty($base['host'])) return null;
        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'];
        $basePath = (string) ($base['path'] ?? '/');
        if (str_starts_with($raw, '/')) $path = $raw;
        else $path = rtrim(dirname($basePath), '/').'/'.$raw;
        $fragment = parse_url($path, PHP_URL_FRAGMENT);
        unset($fragment);
        $query = parse_url($path, PHP_URL_QUERY);
        $pathOnly = (string) (parse_url($path, PHP_URL_PATH) ?: '/');
        $segments = [];
        foreach (explode('/', $pathOnly) as $segment) {
            if ($segment === '' || $segment === '.') continue;
            if ($segment === '..') array_pop($segments); else $segments[] = $segment;
        }
        $resolved = $scheme.'://'.$host.'/'.implode('/', $segments);
        return $query !== null ? $resolved.'?'.$query : $resolved;
    }

    private function text(mixed $value, int $limit): ?string
    {
        if ($value === null || ! is_scalar($value)) return null;
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? (string) $value);
        if ($value === '') return null;
        $value = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu', '[email]', $value) ?? $value;
        return Str::limit($value, $limit, '…');
    }

    private function selectorHint(Crawler $node): ?string
    {
        $id = trim((string) $node->attr('id'));
        if ($id !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_:-]*$/', $id)) return '#'.$id;

        $testAttribute = $node->attr('data-testid') !== null ? 'data-testid' : 'data-test';
        $testId = trim((string) ($node->attr($testAttribute) ?: ''));
        if ($testId !== '') return '['.$testAttribute.'="'.addslashes($testId).'"]';

        $name = trim((string) $node->attr('name'));
        $tag = strtolower($node->nodeName());
        if ($name !== '') return $tag.'[name="'.addslashes($name).'"]';

        $classes = preg_split('/\s+/u', trim((string) $node->attr('class')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $classes = array_values(array_filter($classes, fn (string $class): bool => preg_match('/^[A-Za-z_-][A-Za-z0-9_-]*$/', $class) === 1));
        return $tag.($classes !== [] ? '.'.implode('.', array_slice($classes, 0, 3)) : '');
    }

    private function selectorPath(Crawler $node): ?string
    {
        $element = $node->getNode(0);
        if (! $element instanceof \DOMElement) return null;

        $segments = [];
        while ($element instanceof \DOMElement) {
            $tag = strtolower($element->tagName);
            $parent = $element->parentNode;
            $sameTagSiblings = [];
            if ($parent !== null) {
                foreach ($parent->childNodes as $sibling) {
                    if ($sibling instanceof \DOMElement && strtolower($sibling->tagName) === $tag) {
                        $sameTagSiblings[] = $sibling;
                    }
                }
            }
            $segment = $tag;
            if ($sameTagSiblings !== [] && count($sameTagSiblings) > 1) {
                $index = array_search($element, $sameTagSiblings, true);
                if ($index !== false) $segment .= ':nth-of-type('.((int) $index + 1).')';
            }
            array_unshift($segments, $segment);
            if ($tag === 'html') break;
            $element = $parent instanceof \DOMElement ? $parent : null;
        }

        return $segments === [] ? null : implode(' > ', $segments);
    }
}
