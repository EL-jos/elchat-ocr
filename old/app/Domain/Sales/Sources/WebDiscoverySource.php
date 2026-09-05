<?php

namespace App\Domain\Sales\Sources;

use App\Domain\Sales\Contracts\ProspectSourceInterface;
use App\Models\Conversation;
use App\Models\Site;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebDiscoverySource implements ProspectSourceInterface
{
    private const SKIP_DOMAINS = ['facebook.com', 'instagram.com', 'linkedin.com', 'youtube.com', 'x.com', 'twitter.com', 'tiktok.com'];

    public function key(): string
    {
        return 'web_discovery';
    }

    public function discover(Site $site, Conversation $conversation, array $icp, int $limit, array $options = []): Collection
    {
        $seeds = array_values(array_filter($options['web_seed_urls'] ?? [], fn ($url) => is_string($url) && $this->safeUrl($url)));
        if (! $seeds) {
            return collect();
        }

        $maxPages = max(1, min(
            (int) ($options['max_pages'] ?? config('prospecting.web_discovery.max_pages', 10)),
            (int) ($options['max_requests_per_source'] ?? 100),
            $limit,
        ));
        $candidates = collect();
        $visited = [];
        $queue = $seeds;

        while ($queue && count($visited) < $maxPages && $candidates->count() < $limit) {
            $url = array_shift($queue);
            $normalized = $this->normalizeUrl($url);
            if (! $normalized || isset($visited[$normalized])) {
                continue;
            }
            $visited[$normalized] = true;

            try {
                $response = Http::timeout((int) ($options['timeout'] ?? config('prospecting.http.timeout', 20)))
                    ->retry((int) ($options['retries'] ?? config('prospecting.http.retries', 2)), 250)
                    ->withHeaders(['User-Agent' => config('prospecting.http.user_agent')])
                    ->get($normalized);
            } catch (\Throwable $exception) {
                Log::warning('Web discovery: page inaccessible', ['url' => $normalized, 'error' => $exception->getMessage()]);

                continue;
            }
            if (! $response->successful()) {
                continue;
            }

            $html = $response->body();
            $candidates = $candidates->merge($this->extractStructuredCandidates($html, $normalized, $icp));
            [$links, $sameHostLinks] = $this->extractLinks($html, $normalized, $icp);
            $candidates = $candidates->merge($links);
            foreach ($sameHostLinks as $link) {
                if (count($queue) + count($visited) >= $maxPages) {
                    break;
                }
                $queue[] = $link;
            }
        }

        return $candidates->unique(fn (array $candidate) => $candidate['domain'] ?? $candidate['external_key'] ?? $candidate['source_url'] ?? $candidate['name'])
            ->take($limit)->values();
    }

    private function extractStructuredCandidates(string $html, string $sourceUrl, array $icp): array
    {
        $candidates = [];
        $isDirectoryPage = $this->looksLikeDirectoryPage($html, $sourceUrl);
        if (preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches)) {
            foreach ($matches[1] as $raw) {
                $json = json_decode(trim(html_entity_decode($raw)), true);
                foreach ($this->flattenJsonLd($json) as $data) {
                    $type = $data['@type'] ?? null;
                    $types = is_array($type) ? $type : [$type];
                    if (! $type || ! array_filter($types, fn ($value) => str_contains(strtolower((string) $value), 'organization') || str_contains(strtolower((string) $value), 'business'))) {
                        continue;
                    }
                    $website = $this->normalizeUrl($data['url'] ?? $sourceUrl);
                    if ($isDirectoryPage && (! $website || $this->sameHost($website, $sourceUrl))) {
                        continue;
                    }
                    $candidates[] = $this->candidate(
                        $data['name'] ?? null,
                        $website,
                        $sourceUrl,
                        $data['email'] ?? null,
                        $data['telephone'] ?? null,
                        is_array($data['address'] ?? null) ? ($data['address']['addressLocality'] ?? null) : null,
                        $data['category'] ?? $data['industry'] ?? (is_string($type) ? $type : null),
                        $data,
                        $this->formatAddress($data['address'] ?? null),
                    );
                }
            }
        }

        $siteName = $this->meta($html, 'og:site_name');
        if ($siteName && ! $isDirectoryPage && $this->pageMatchesIcp($html, $icp)) {
            $candidates[] = $this->candidate($siteName, $sourceUrl, $sourceUrl, null, null, null, null, ['title' => $siteName, 'identity' => 'direct_business_page']);
        }

        return array_values(array_filter($candidates));
    }

    private function extractLinks(string $html, string $sourceUrl, array $icp): array
    {
        $external = [];
        $sameHost = [];
        $baseHost = parse_url($sourceUrl, PHP_URL_HOST);
        $isDirectoryPage = $this->looksLikeDirectoryPage($html, $sourceUrl);
        if (preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $matches)) {
            foreach ($matches[1] as $index => $href) {
                $url = $this->normalizeUrl($href, $sourceUrl);
                if (! $url || ! $this->safeUrl($url)) {
                    continue;
                }
                $host = strtolower((string) parse_url($url, PHP_URL_HOST));
                if (! $host || $this->isSkippedDomain($host)) {
                    continue;
                }
                $label = trim(preg_replace('/\s+/', ' ', strip_tags($matches[2][$index] ?? '')));
                if ($host === strtolower((string) $baseHost)) {
                    if ($label !== '' && ! str_contains($url, '#')) {
                        $sameHost[] = $url;
                    }

                    continue;
                }
                if ($label === '' || mb_strlen($label) < 2 || $this->isGenericLinkLabel($label)) {
                    continue;
                }
                if (! $isDirectoryPage && ! $this->pageMatchesIcp($label.' '.$url, $icp)) {
                    continue;
                }
                $external[] = $this->candidate($label, $url, $sourceUrl, null, null, null, null, [
                    'anchor_text' => $label,
                    'discovery_context' => $isDirectoryPage ? 'directory_entry' : 'external_link',
                ]);
            }
        }

        return [$external, array_values(array_unique($sameHost))];
    }

    private function candidate(?string $name, ?string $website, string $sourceUrl, ?string $email, ?string $phone, ?string $location, ?string $sector, array $value, ?string $address = null): ?array
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }
        $website = $this->normalizeUrl($website);

        return [
            'name' => $name, 'company' => $name, 'website' => $website,
            'domain' => $website ? parse_url($website, PHP_URL_HOST) : null,
            'email' => $email, 'phone' => $phone, 'location' => $location,
            'address' => $address ?: $location,
            'sector' => $sector, 'external_key' => 'web:'.hash('sha256', ($website ?: $sourceUrl).'|'.mb_strtolower($name)),
            'source_url' => $sourceUrl,
            'evidence' => [[
                'type' => 'observation', 'field' => 'public_web_data', 'value' => $value,
                'source_url' => $sourceUrl, 'confidence' => 0.85,
            ]],
        ];
    }

    private function formatAddress(mixed $address): ?string
    {
        if (is_string($address)) {
            return trim($address) ?: null;
        }
        if (! is_array($address)) {
            return null;
        }

        return collect([
            $address['streetAddress'] ?? null,
            $address['postalCode'] ?? null,
            $address['addressLocality'] ?? null,
            $address['addressRegion'] ?? null,
            $address['addressCountry'] ?? null,
        ])->filter(fn ($value) => is_scalar($value) && trim((string) $value) !== '')
            ->map(fn ($value) => trim((string) $value))
            ->implode(', ') ?: null;
    }

    private function flattenJsonLd(mixed $json): array
    {
        if (! is_array($json)) {
            return [];
        }
        if (array_is_list($json)) {
            return array_merge(...array_map(fn ($item) => $this->flattenJsonLd($item), $json));
        }
        $items = [$json];
        if (isset($json['@graph']) && is_array($json['@graph'])) {
            $items = array_merge($items, $this->flattenJsonLd($json['@graph']));
        }

        return $items;
    }

    private function meta(string $html, string $property): ?string
    {
        return preg_match('/<meta[^>]+(?:property|name)=["\']'.preg_quote($property, '/').'["\'][^>]+content=["\']([^"\']*)["\']/i', $html, $m) ? trim($m[1]) : null;
    }

    private function title(string $html): ?string
    {
        return preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m) ? trim(strip_tags($m[1])) : null;
    }

    private function normalizeUrl(?string $url, ?string $base = null): ?string
    {
        if (! $url) {
            return null;
        }
        $url = trim(html_entity_decode($url));
        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        }
        if (! preg_match('#^https?://#i', $url)) {
            if (! $base) {
                return null;
            }
            $url = rtrim($base, '/').'/'.ltrim($url, '/');
        }

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    private function safeUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (! $host || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return false;
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false && filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }

        return ! $this->isSkippedDomain($host);
    }

    private function isSkippedDomain(string $host): bool
    {
        return collect(self::SKIP_DOMAINS)->contains(fn ($domain) => $host === $domain || str_ends_with($host, '.'.$domain));
    }

    private function looksLikeDirectoryPage(string $html, string $url): bool
    {
        $haystack = mb_strtolower($url.' '.strip_tags($html));
        foreach (['annuaire', 'directory', 'estate-agents', 'real-estate', 'listings', 'entreprises', 'businesses', 'professionals', 'fournisseurs', 'providers'] as $marker) {
            if (str_contains($haystack, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function pageMatchesIcp(string $value, array $icp): bool
    {
        $haystack = mb_strtolower($value);
        foreach ([$icp['sector'] ?? '', $icp['company_type'] ?? '', $icp['needs'] ?? '', $icp['custom_criteria'] ?? ''] as $field) {
            foreach (preg_split('/[,;|]+/u', mb_strtolower((string) $field)) ?: [] as $term) {
                $term = trim($term);
                if (mb_strlen($term) >= 5 && str_contains($haystack, $term)) {
                    return true;
                }
                foreach (preg_split('/[^\p{L}\p{N}]+/u', $term) ?: [] as $token) {
                    if (mb_strlen($token) >= 6 && str_contains($haystack, $token)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function sameHost(string $first, string $second): bool
    {
        return strtolower((string) parse_url($first, PHP_URL_HOST)) === strtolower((string) parse_url($second, PHP_URL_HOST));
    }

    private function isGenericLinkLabel(string $label): bool
    {
        return in_array(mb_strtolower(trim($label)), ['click here', 'read more', 'learn more', 'website', 'site web', 'voir', 'details', 'détails'], true);
    }
}
