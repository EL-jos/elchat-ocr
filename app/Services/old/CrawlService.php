<?php

namespace App\Services;

use App\Models\Page;
use App\Models\Site;
use App\Models\WidgetSetting;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\HttpClient;
use Throwable;
use App\Jobs\vision\ProcessImageOcrJob;
use App\Models\PageImage;
use App\Services\hops\LLMService;

class CrawlService {
    public function prepareQueue(Site $site): array
    {
        $queue = [];
        $visited = [];

        $baseUrl  = rtrim($site->url, '/') . '/';
        $baseHost = parse_url($baseUrl, PHP_URL_HOST);

        if (!empty($site->include_pages)) {
            foreach ($site->include_pages as $path) {
                $queue[] = [
                    'url'   => $this->resolveUrl($path, $baseUrl),
                    'depth' => 0,
                ];
            }
        } else {
            $queue[] = ['url' => $baseUrl, 'depth' => 0];
        }

        $allUrls = [];

        while ($queue) {
            $current = array_shift($queue);
            $url     = $this->normalizeUrl($current['url']);
            $depth   = $current['depth'];

            if (!$url || $depth > $site->crawl_depth) continue;
            if (in_array($url, $visited, true)) continue;

            if ($this->isExcluded($url, $site)) continue;

            $visited[] = $url;
            $allUrls[] = ['url' => $url, 'depth' => $depth];

            foreach ($this->extractInternalLinks($url, $baseHost, $site) as $link) {
                if (!in_array($link, $visited, true)) {
                    $queue[] = ['url' => $link, 'depth' => $depth + 1];
                }
            }
        }

        return $allUrls;
    }
    public function crawlSinglePage(
        Site $site,
        string $url,
        int $depth,
        ?string $crawlJobId = null
    ): ?Page {

        if ($this->isExcluded($url, $site)) {
            return null;
        }

        try {
            $client = HttpClient::create([
                'timeout' => 30,
                'headers' => $this->getHeaders(),
            ]);

            $response = $client->request('GET', $url);
            $status = $response->getStatusCode();

            if ($status >= 400) {
                Log::warning("Bad status {$status} for {$url}");
                return null;
            }
            $html = $response->getContent(false); // IMPORTANT

            if (!$html) return null;

            $crawler = new Crawler($html);

            // ✅ 1. Normalisation URL (CRITICAL)
            $canonical = $this->extractCanonicalUrl($crawler);

            if ($canonical) {
                $canonical = $this->resolveUrl($canonical, $url);

                if (parse_url($canonical, PHP_URL_HOST) !== parse_url($url, PHP_URL_HOST)) {
                    $canonical = $url;
                }
            } else {
                $canonical = $url;
            }

            $canonical = $this->normalizeUrl($canonical);

            // ✅ 2. Extraction META intelligente
            $meta = $this->extractMeta($crawler);

            // ✅ 3. Détection type page
            $pageType = $this->detectPageType($crawler, $url);

            // ✅ 4. Extraction contenu principal
            $main = $this->extractBestContent($crawler);
            if (!$main) return null;

            // ✅ 5. Nettoyage avancé
            $this->cleanDomAdvanced($main);

            // ✅ 6. Extraction sections hiérarchiques
            $sections = $this->extractHierarchicalSections($main);

            if (empty($sections)) {
                $sections = $this->extractLooseSections($main);
            }

            $schemas = $this->extractSchemaOrg($crawler);

            // transformer schema en contenu RAG
            $structuredSections = $this->extractStructuredDataAsText($schemas);

            // fusionner avec le contenu classique
            $sections = array_merge($sections, $structuredSections);

            // ✅ 7. Score importance
            $importance = $this->computeImportance($url, $depth, $pageType);

            $links = $this->extractLinksFromCrawler($crawler, $site);

            return Page::create([
                'id'           => (string) Str::uuid(),
                'site_id'      => $site->id,
                'crawl_job_id' => $crawlJobId,
                'url'          => $canonical,
                'title'        => $meta['title'] ?? 'Untitled page',
                'content'      => json_encode([
                    'sections' => $sections,
                    'type'     => $pageType,
                    'meta'     => $meta,
                    'importance' => $importance,
                    'keywords' => $meta['keywords'],
                    'schemas'   => $schemas,
                ], JSON_UNESCAPED_UNICODE),
                'plain_text' => $this->buildPlainText($sections),
                'source'       => 'crawl',
                'is_indexed' => false,
            ]);

        } catch (Throwable $e) {
            Log::error("Crawl error {$url}", ['error' => $e->getMessage()]);
            return null;
        }
    }
    private function cleanDomAdvanced(Crawler $crawler): void
    {
        $selectors = [
            'script','style','nav','footer','header','aside',
            'form','iframe','button','svg',
            '[class*="cookie"]',
            '[class*="footer"]',
            '[class*="sidebar"]',
            '[class*="related"]',
            '[class*="share"]',
            '[id*="cookie"]',
            '[id*="footer"]'
        ];

        $crawler->filter(implode(',', $selectors))
            ->each(fn ($n) => $n->getNode(0)?->parentNode?->removeChild($n->getNode(0)));
    }
    private function extractHierarchicalSections(Crawler $content): array
    {
        $sections = [];
        $h1 = null;
        $h2 = null;
        $h3 = null;

        $content->filter('h1,h2,h3,p,li')->each(function (Crawler $node) use (&$sections, &$h1, &$h2, &$h3) {

            $tag = strtolower($node->nodeName());
            $text = trim($node->text());

            if (!$text) return;

            if ($tag === 'h1') {
                $h1 = $text;
                $h2 = null;
                $h3 = null;
                return;
            }

            if ($tag === 'h2') {
                $h2 = $text;
                $h3 = null;
                return;
            }

            if ($tag === 'h3') {
                $h3 = $text;
                return;
            }

            if (in_array($tag, ['p', 'li'])) {
                $sections[] = [
                    'h1' => $h1,
                    'h2' => $h2,
                    'h3' => $h3,
                    'content' => $text,
                    'weight' => $this->getTagWeight($tag),
                ];
            }
        });

        return $sections;
    }
    private function getTagWeight(string $tag): float
    {
        return match($tag) {
            'h1' => 3.0,
            'h2' => 2.0,
            'h3' => 1.5,
            'p'  => 1.0,
            'li' => 0.8,
            default => 1.0
        };
    }
    private function extractMeta(Crawler $crawler): array
    {
        return [
            'title' => $crawler->filter('title')->count()
                ? trim($crawler->filter('title')->text())
                : null,

            'description' => $crawler->filter('meta[name="description"]')->count()
                ? $crawler->filter('meta[name="description"]')->attr('content')
                : null,

            'keywords' => $this->extractKeywords($crawler),

            'language' => $crawler->filter('html')->attr('lang') ?? 'unknown',
        ];
    }
    private function detectPageType(Crawler $crawler, string $url): string
    {
        if (str_contains($url, 'blog')) return 'article';
        if ($crawler->filter('article')->count()) return 'article';
        if ($crawler->filter('[class*="product"]')->count()) return 'product';
        if ($crawler->filter('form')->count()) return 'landing';

        return 'generic';
    }
    private function computeImportance(string $url, int $depth, string $type): float
    {
        $score = 1.0;

        if ($depth === 0) $score += 3;
        if (preg_match('/(contact|about|pricing)/', $url)) $score += 2;
        if ($type === 'product') $score += 2;
        if ($type === 'article') $score += 1.5;

        return min($score, 5);
    }
    public function isExcluded(string $url, Site $site): bool
    {
        foreach ($site->exclude_pages ?? [] as $pattern) {
            if ($this->urlMatchesPattern($url, $pattern)) return true;
        }
        return false;
    }
    private function urlMatchesPattern(string $url, string $pattern): bool
    {
        $pattern = rtrim($pattern, '/');
        $url     = rtrim($url, '/');

        if (str_contains($pattern, '*')) {
            $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#i';
            return (bool) preg_match($regex, $url);
        }

        return $url === $pattern || str_starts_with($url . '/', $pattern . '/');
    }
    public function normalizeUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['host'])) return null;

        $scheme = strtolower($parts['scheme'] ?? 'http');
        $host   = strtolower($parts['host']);
        $path   = $this->normalizePath($parts['path'] ?? '/');

        return $scheme . '://' . $host . $path;
    }
    private function normalizePath(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $p) {
            if ($p === '' || $p === '.') continue;
            if ($p === '..') array_pop($parts);
            else $parts[] = $p;
        }
        return '/' . implode('/', $parts);
    }
    private function extractBestContent(Crawler $crawler): ?Crawler
    {
        foreach (['main', 'article', '[role="main"]'] as $selector) {
            if ($crawler->filter($selector)->count()) {
                return $crawler->filter($selector);
            }
        }

        // Heuristique densité de texte
        $bestNode  = null;
        $bestScore = 0;

        $crawler->filter('div, section')->each(function (Crawler $node) use (&$bestNode, &$bestScore) {
            $textLength = mb_strlen(trim($node->text()));
            $linkCount  = $node->filter('a')->count();

            //if ($textLength < 300) return;
            //if ($linkCount > ($textLength / 100)) return;

            $score = $textLength - ($linkCount * 50);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestNode  = $node;
            }
        });

        return $bestNode ?: ($crawler->filter('body')->count() ? $crawler->filter('body') : null);
    }
    private function extractLooseSections(Crawler $content): array
    {
        $text = trim(preg_replace('/\s+/', ' ', $content->text()));
        if (mb_strlen($text) < 0/*300*/) return [];

        $sentences = preg_split('/(?<=[.!?])\s+/', $text);
        $sections = [];
        $buffer = '';

        foreach ($sentences as $sentence) {
            if (mb_strlen($buffer) < 300) {
                $buffer .= ' ' . $sentence;
            } else {
                $sections[] = [
                    'title'   => null,
                    'content' => trim($buffer),
                ];
                $buffer = $sentence;
            }
        }

        if (mb_strlen($buffer) > 0/*200*/) {
            $sections[] = [
                'title'   => null,
                'content' => trim($buffer),
            ];
        }

        return $sections;
    }
    private function getHeaders(): array
    {
        return [
            'User-Agent' => $this->getRandomUserAgent(),
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7',
            'Connection' => 'keep-alive',
            'Upgrade-Insecure-Requests' => '1',
            'Cache-Control' => 'no-cache',
        ];
    }
    private function getRandomUserAgent(): string
    {
        $agents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/122.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 13_5) AppleWebKit/537.36 Chrome/121.0.0.0 Safari/537.36',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
        ];

        return $agents[array_rand($agents)];
    }
    private function extractCanonicalUrl(Crawler $crawler): ?string
    {
        try {
            if ($crawler->filter('link[rel="canonical"]')->count()) {
                return trim(
                    $crawler->filter('link[rel="canonical"]')->attr('href')
                );
            }

            // fallback OpenGraph
            if ($crawler->filter('meta[property="og:url"]')->count()) {
                return trim(
                    $crawler->filter('meta[property="og:url"]')->attr('content')
                );
            }

            return null;

        } catch (Throwable $e) {
            return null;
        }
    }
    private function extractKeywords(Crawler $crawler): array
    {
        $keywords = [];

        // meta keywords
        if ($crawler->filter('meta[name="keywords"]')->count()) {
            $content = $crawler->filter('meta[name="keywords"]')->attr('content');
            $keywords = array_merge($keywords, explode(',', $content));
        }

        // h1 / h2
        $crawler->filter('h1,h2,h3')->each(function (Crawler $node) use (&$keywords) {
            $text = trim($node->text());
            if ($text) {
                $keywords[] = $text;
            }
        });

        // title
        if ($crawler->filter('title')->count()) {
            $keywords[] = trim($crawler->filter('title')->text());
        }

        // nettoyage
        $keywords = array_map(fn($k) => strtolower(trim($k)), $keywords);
        $keywords = array_filter($keywords);
        $keywords = array_unique($keywords);

        $keywords = array_merge(
            $keywords,
            $this->extractKeywordsFromText($crawler->text())
        );

        return array_values($keywords);
    }
    private function extractKeywordsFromText(string $text, int $limit = 10): array
    {
        $text = strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text);

        $words = preg_split('/\s+/', $text);

        $stopwords = ['le','la','les','de','des','un','une','et','en','du','a','à','est','sont','avec','pour','sur','dans','par','plus','ou','mais','the','and','of','to'];

        $freq = [];

        foreach ($words as $word) {
            if (strlen($word) < 3) continue;
            if (in_array($word, $stopwords)) continue;

            //$freq[$word] = ($freq[$word] ?? 0) + 1;

            $boost = 1;

            //if (str_contains($word, '-')) $boost += 0.3;
            if (strlen($word) > 8) $boost += 0.3;

            //$freq[$word] = ($freq[$word] ?? 0) + $boost;
            $freq[$word] = min(($freq[$word] ?? 0) + $boost, 5);
        }


        arsort($freq);

        return array_slice(array_keys($freq), 0, $limit);
    }
    private function resolveUrl(?string $url, string $baseUrl): ?string
    {
        if (!$url) return null;

        $url = trim($url);

        // déjà absolue
        if (parse_url($url, PHP_URL_SCHEME)) {
            return $url;
        }

        $baseParts = parse_url($baseUrl);
        if (!$baseParts || empty($baseParts['host'])) return null;

        $scheme = $baseParts['scheme'] ?? 'https';
        $host   = $baseParts['host'];

        // relative root "/page"
        if (str_starts_with($url, '/')) {
            return "{$scheme}://{$host}{$url}";
        }

        // relative "page.html"
        $path = $baseParts['path'] ?? '/';
        $path = rtrim(dirname($path), '/');

        return "{$scheme}://{$host}{$path}/{$url}";
    }
    private function extractLinksFromCrawler(Crawler $crawler, Site $site): array
    {
        $links = [];
        $baseUrl = rtrim($site->url, '/') . '/';
        $baseHost = parse_url($baseUrl, PHP_URL_HOST);
        try {

            $crawler->filter('a[href]')->each(function (Crawler $node) use (&$links, $baseUrl, $baseHost, $site) {

                $href = trim($node->attr('href'));
                if (!$href) return;

                // ignorer liens inutiles
                if (preg_match('/^(#|mailto|tel|javascript|data):/i', $href)) return;

                $abs = $this->resolveUrl($href, $baseUrl);
                if (!$abs) return;

                $normalized = $this->normalizeUrl($abs);
                if (!$normalized) return;

                // même domaine uniquement
                if (parse_url($normalized, PHP_URL_HOST) !== $baseHost) return;

                if ($this->isExcluded($normalized, $site)) return;

                $links[$normalized] = true; // hash set
            });
        }catch (Throwable $e) {
            Log::warning("Link extraction failed {$e->getMessage()}");
        }
        return array_keys($links);
    }
    private function buildPlainText(array $sections): string
    {
        $parts = [];
        $lastH1 = null;
        $lastH2 = null;
        $lastH3 = null;

        foreach ($sections as $section) {

            $block = [];

            if (($section['h1'] ?? null) !== $lastH1) {
                $block[] = $section['h1'];
                $lastH1 = $section['h1'];
            }

            if (($section['h2'] ?? null) !== $lastH2) {
                $block[] = $section['h2'];
                $lastH2 = $section['h2'];
            }

            if (($section['h3'] ?? null) !== $lastH3) {
                $block[] = $section['h3'];
                $lastH3 = $section['h3'];
            }

            $block[] = $section['content'] ?? null;

            $parts[] = implode(' - ', array_filter($block));
        }

        return trim(implode("\n\n", $parts));
    }
    private function extractSchemaOrg(Crawler $crawler): array
    {
        $data = [];

        $crawler->filter('script[type="application/ld+json"]')
            ->each(function (Crawler $node) use (&$data) {

                try {
                    $json = json_decode($node->text(), true, 512, JSON_THROW_ON_ERROR);

                    if (is_array($json)) {
                        $data[] = $json;
                    }

                } catch (\Throwable $e) {
                    // ignore JSON invalide
                }
            });

        return $data;
    }
    private function extractStructuredDataAsText(array $schemas): array
    {
        $sections = [];

        foreach ($schemas as $schema) {

            if (($schema['@type'] ?? null) === 'FAQPage') {

                foreach ($schema['mainEntity'] ?? [] as $item) {
                    $sections[] = [
                        'h1' => 'FAQ',
                        'h2' => $item['name'] ?? null,
                        'content' => strip_tags($item['acceptedAnswer']['text'] ?? ''),
                        'weight' => 2.5
                    ];
                }
            }

            if (($schema['@type'] ?? null) === 'Product') {
                $sections[] = [
                    'h1' => 'Product',
                    'h2' => $schema['name'] ?? null,
                    'content' => ($schema['description'] ?? ''),
                    'weight' => 2.5
                ];
            }

            if (($schema['@type'] ?? null) === 'Article') {
                $sections[] = [
                    'h1' => 'Article',
                    'h2' => $schema['headline'] ?? null,
                    'content' => $schema['description'] ?? '',
                    'weight' => 2.0
                ];
            }
        }

        return $sections;
    }
    public function processRawContent(
        Site $site,
        string $rawContent,
        array $meta = [],
        ?string $url = null
    ): array
    {
        // Si texte brut, wrapper en HTML
        $html = strip_tags($rawContent) === $rawContent
            ? "<html><body>{$rawContent}</body></html>"
            : $rawContent;

        $crawler = new Crawler($html);

        // Nettoyage avancé
        $main = $this->extractBestContent($crawler);
        if ($main) $this->cleanDomAdvanced($main);

        // Sections hiérarchiques
        $sections = $main ? $this->extractHierarchicalSections($main) : [];
        if (empty($sections)) {
            $sections = $this->extractLooseSections($crawler);
        }

        // Keywords
        $keywords = array_merge(
            $meta['keywords'] ?? [],
            $this->extractKeywordsFromText($crawler->text())
        );
        $keywords = array_unique($keywords);

        // Type CMS
        $type = 'article';

        // Importance par défaut CMS
        $importance = 2.5;
        if (!empty($meta['published_at'])) {
            $importance += 0.5;
        }

        // Meta enrichie
        $meta = array_merge([
            'title' => null,
            'description' => null,
            'keywords' => [],
            'language' => 'unknown',
        ], $meta);

        return [
            'content' => json_encode([
                'sections'   => $sections,
                'type'       => $type,
                'meta'       => $meta,
                'importance' => $importance,
                'keywords'   => $keywords,
                'schemas'    => [],
            ], JSON_UNESCAPED_UNICODE),
            'plain_text' => $this->buildPlainText($sections),
        ];
    }
    public function processManualContentWithAI(
        Site $site,
        string $rawContent,
        array $meta = [],
        ?string $url = null
    ): array {

        // 🔒 Sécurité minimale
        $rawContent = trim($rawContent);
        if (strlen($rawContent) < 20) {
            return $this->processRawContent($site, $rawContent, $meta, $url);
        }

        // ⚡ Optimisation coût : skip LLM si contenu trop simple
        if (strlen($rawContent) < 250) {
            return $this->processRawContent($site, $rawContent, $meta, $url);
        }

        // 🧠 Prompt structuration
        $prompt = [
            'system' => <<<EOT
Tu es un système expert en structuration de contenu pour un moteur RAG (Retrieval-Augmented Generation).

Ta mission est d’analyser un texte libre fourni par un administrateur et de le transformer en une structure exploitable pour un assistant IA.

Tu dois retourner STRICTEMENT un JSON valide, sans aucun texte en dehors.

---

OBJECTIFS :

- Découper le contenu en sections logiques et cohérentes
- Identifier les intentions principales du contenu
- Extraire les informations importantes sous forme d’entités génériques
- Reformuler le contenu pour optimiser la compréhension par un modèle IA

---

FORMAT JSON ATTENDU :

{
  "type": "string",
  "intents": ["string"],
  "entities": [
    {
      "label": "string",
      "value": "string"
    }
  ],
  "sections": [
    {
      "title": "string",
      "content": "string",
      "intent": "string"
    }
  ],
  "rag_text": "string"
}

---

DÉFINITION DES PROPRIÉTÉS :

- "type":
  Catégorie globale du contenu.
  Exemples : "faq", "product", "contact", "pricing", "policy", "custom".
  Choisir la catégorie la plus pertinente selon le sens du texte.

- "intents":
  Liste des intentions utilisateur couvertes par ce contenu.
  Exemples :
  ["contact", "support", "livraison", "pricing", "information", "retour", "garantie"]
  → Toujours utiliser des mots simples et normalisés.

- "entities":
  Liste d’informations clés extraites du texte, sous forme générique.
  Ne PAS supposer de schéma fixe.

  Chaque entité doit avoir :
  - "label": type de donnée (ex: "ville", "délai", "prix", "service", "produit")
  - "value": valeur exacte trouvée dans le texte

  Exemple :
  { "label": "délai", "value": "48h" }

- "sections":
  Liste de blocs de contenu structurés.

  Chaque section doit contenir :
  - "title": titre court résumant la section
  - "content": contenu clair, reformulé si nécessaire
  - "intent": intention principale de cette section

  Les sections doivent être :
  - cohérentes
  - non redondantes
  - faciles à comprendre pour une IA

- "rag_text":
  Version optimisée du contenu pour la recherche sémantique.

  Doit être :
  - fluide et naturel
  - bien structuré
  - explicite (ne pas supposer de contexte externe)
  - reformulé pour répondre facilement à des questions utilisateur

---

RÈGLES IMPORTANTES :

- Retourner UNIQUEMENT du JSON valide
- Ne jamais ajouter de texte hors JSON
- Ne pas inventer d’informations non présentes dans le texte
- Si une information est absente, ne pas l’ajouter
- Toujours privilégier la clarté et la précision

---
EOT,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $rawContent
                ]
            ]
        ];

        // 🔁 Appel LLM (avec TON retry déjà robuste)
        $llmOutput = $this->callLLM($site, $prompt, substr($rawContent, 0, 200));

        // 🧪 Nettoyage output (important en prod)
        $cleaned = trim($llmOutput);
        $cleaned = preg_replace('/^```json|```$/i', '', $cleaned);

        $data = json_decode($cleaned, true);

        // ❌ Si JSON invalide → fallback safe
        if (!$data || !isset($data['sections']) || !is_array($data['sections'])) {
            Log::warning("LLM JSON invalide → fallback", [
                'site_id' => $site->id,
                'output' => substr($llmOutput, 0, 500)
            ]);

            return $this->processRawContent($site, $rawContent, $meta, $url);
        }

        // 🧱 Normalisation safe
        $type = $data['type'] ?? 'custom';
        $intents = is_array($data['intents'] ?? null) ? $data['intents'] : [];
        $entities = is_array($data['entities'] ?? null) ? $data['entities'] : [];

        // 🧩 Transformation sections → format interne
        $sections = [];

        foreach ($data['sections'] as $section) {

            if (empty($section['content'])) continue;

            $sections[] = [
                'h1' => $meta['title'] ?? 'Contenu manuel',
                'h2' => $section['title'] ?? ($section['intent'] ?? null),
                'h3' => null,
                'content' => trim($section['content']),
                'weight' => 1.5
            ];
        }

        // ❌ Sécurité : fallback si vide
        if (empty($sections)) {
            return $this->processRawContent($site, $rawContent, $meta, $url);
        }

        // ✨ Plain text optimisé RAG
        $plainText = $data['rag_text'] ?? null;

        if (!$plainText || strlen($plainText) < 30) {
            $plainText = $this->buildPlainText($sections);
        }

        // 🔥 Enrichissement (gros boost retrieval)
        $plainText = "Contexte: informations fournies par l'entreprise.\n\n" . $plainText;

        // 🧠 Keywords auto
        $keywords = array_merge(
            $meta['keywords'] ?? [],
            $this->extractKeywordsFromText($plainText)
        );

        $keywords = array_values(array_unique($keywords));

        // ⚖️ Importance intelligente
        $importance = 2.5;
        if (!empty($intents)) $importance += 0.5;
        if (count($sections) > 3) $importance += 0.5;

        // 🧾 Payload final
        return [
            'content' => json_encode([
                'sections'   => $sections,
                'type'       => $type,
                'meta'       => $meta,
                'importance' => $importance,
                'keywords'   => $keywords,
                'schemas'    => [],
                'intents'    => $intents,
                'entities'   => $entities,
                'source'     => 'manual',
                'raw'        => $rawContent // debug + future training
            ], JSON_UNESCAPED_UNICODE),

            'plain_text' => $plainText,

            'type' => $type,
            'importance' => $importance,
            'intents' => $intents,
            'entities' => $entities,
        ];
    }

    private function callLLM(Site $site, array $prompt, string $context = ''): string
    {
        $companyName = $site->name ?? parse_url($site->url, PHP_URL_HOST);

        /** @var WidgetSetting $settings */
        $settings = $site->settings;

        $messages = [
            ['role' => 'system', 'content' => $prompt['system']],
            ...($prompt['messages'] ?? []),
        ];

        try {
            return app(LLMService::class)->chat($messages, [
                'task' => 'crawl',
                'temperature' => (float) ($settings->ai_temperature ?? 0.2),
                'max_tokens' => (int) ($settings->ai_max_tokens ?? 800),
            ]);
        } catch (Throwable $exception) {
            Log::warning('Ancien CrawlService : appel LLM centralisé indisponible', [
                'site_id' => $site->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return json_encode([
            'type' => 'fallback',
            'intents' => ['information'],
            'entities' => [],
            'sections' => [[
                'title' => 'Information',
                'content' => $context ?: 'Information non disponible',
                'intent' => 'information',
            ]],
            'rag_text' => $context ?: "Informations fournies par {$companyName}",
        ], JSON_UNESCAPED_UNICODE);

        $maxRetries = 5;
        $delaySeconds = 1;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {

            try {
                Log::info("LLM call attempt {$attempt}", [
                    'site_id' => $site->id,
                    'context' => substr($context, 0, 100),
                ]);

                $response = Http::timeout(30)
                    ->connectTimeout(10)
                    ->withHeaders([
                        'Authorization' => 'Bearer ' . env('OPENROUTER_API_KEY'),
                        'Content-Type' => 'application/json',
                        'HTTP-Referer' => config('app.url'), // recommandé OpenRouter
                        'X-Title' => 'RAG SaaS Engine',
                    ])
                    ->post('https://openrouter.ai/api/v1/chat/completions', [
                        'model' => config('llm.tasks.crawl.model'),
                        'messages' => $messages,
                        'temperature' => floatval($settings->ai_temperature ?? 0.2),
                        'max_tokens' => intval($settings->ai_max_tokens ?? 800),
                    ]);

                // ❌ HTTP error
                if (!$response->successful()) {
                    Log::warning("LLM HTTP error", [
                        'attempt' => $attempt,
                        'status' => $response->status(),
                        'body' => substr($response->body(), 0, 500),
                    ]);

                    if ($attempt < $maxRetries) {
                        sleep($delaySeconds);
                        $delaySeconds *= 2;
                        continue;
                    }

                    break;
                }

                $data = $response->json();

                // ❌ mauvaise structure
                if (
                    !isset($data['choices'][0]['message']['content'])
                ) {
                    Log::warning("LLM invalid structure", [
                        'attempt' => $attempt,
                        'response' => $data
                    ]);

                    if ($attempt < $maxRetries) {
                        sleep($delaySeconds);
                        $delaySeconds *= 2;
                        continue;
                    }

                    break;
                }

                $content = trim($data['choices'][0]['message']['content']);

                // 🧹 nettoyage important (JSON safe)
                $content = preg_replace('/^```json|```$/i', '', $content);
                $content = trim($content);

                // ❌ contenu vide
                if (strlen($content) < 5) {
                    Log::warning("LLM empty response", ['attempt' => $attempt]);

                    if ($attempt < $maxRetries) {
                        sleep($delaySeconds);
                        $delaySeconds *= 2;
                        continue;
                    }

                    break;
                }

                Log::info("LLM success", [
                    'attempt' => $attempt,
                    'length' => strlen($content),
                ]);

                return $content;

            } catch (RequestException $e) {

                Log::warning("LLM request exception", [
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);

            } catch (Throwable $e) {

                Log::error("LLM unexpected error", [
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
            }

            // 🔁 retry avec backoff
            if ($attempt < $maxRetries) {
                sleep($delaySeconds);
                $delaySeconds *= 2;
            }
        }

        // ❌ échec total
        Log::error("LLM failed after {$maxRetries} attempts", [
            'site_id' => $site->id,
            'context' => substr($context, 0, 200),
        ]);

        // 🔥 fallback safe (important pour ne pas casser pipeline)
        return json_encode([
            'type' => 'fallback',
            'intents' => ['information'],
            'entities' => [],
            'sections' => [
                [
                    'title' => 'Information',
                    'content' => $context ?: 'Information non disponible',
                    'intent' => 'information'
                ]
            ],
            'rag_text' => $context ?: "Informations fournies par {$companyName}"
        ], JSON_UNESCAPED_UNICODE);
    }

    private function extractInternalLinks(string $url, string $baseHost, Site $site): array
    {
        $links = [];

        try {
            $client = new HttpBrowser(HttpClient::create(['timeout' => 30]));
            $client->request('GET', $url);
            $crawler = $client->getCrawler();

            $crawler->filter('a[href]')->each(function (Crawler $node) use (&$links, $baseHost, $site) {
                $href = trim($node->attr('href'));
                if (!$href || preg_match('/^(#|mailto|tel|javascript|data):/i', $href)) return;

                $abs = $this->resolveUrl($href, rtrim($site->url, '/') . '/');
                if (!$abs) return;

                if (parse_url($abs, PHP_URL_HOST) !== $baseHost) return;

                $norm = $this->normalizeUrl($abs);
                if ($this->isExcluded($norm, $site)) return;

                if (!in_array($norm, $links, true)) {
                    $links[] = $norm;
                }
            });
        } catch (\Throwable $e) {
            Log::warning("Link extraction failed {$url}");
        }

        return $links;
    }
}
