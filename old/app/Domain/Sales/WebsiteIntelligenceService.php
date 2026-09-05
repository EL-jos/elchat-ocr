<?php

namespace App\Domain\Sales;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Analyse des signaux publics d'un site de prospect, avec limite de pages. */
class WebsiteIntelligenceService
{
    private const KNOWN_CHAT_WIDGET_MARKERS = [
        'intercom', 'drift.com', 'crisp.chat', 'tawk.to', 'zendesk', 'livechatinc',
        'hubspot-chat', 'elchat', 'tidio', 'freshchat',
    ];

    private const SOCIAL_DOMAINS = ['facebook.com', 'instagram.com', 'linkedin.com', 'twitter.com', 'x.com', 'youtube.com', 'tiktok.com'];

    /** @return array{has_chatbot:bool, contact_form_only:bool, social_activity_score:int, has_competitor_solution:bool, page_title:?string, fetch_error:?string, pages_analyzed:int, icp_matches:array<string, array<int, string>>} */
    public function analyze(string $url, int $maxPages = 1, array $icp = []): array
    {
        $url = str_starts_with($url, 'http') ? $url : "https://{$url}";
        $maxPages = max(1, min(20, $maxPages));
        $queue = [$url];
        $visited = [];
        $bodies = [];
        $firstError = null;

        while ($queue && count($visited) < $maxPages) {
            $current = array_shift($queue);
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;

            try {
                $response = Http::timeout(6)->withHeaders(['User-Agent' => 'ELChatSalesHunter/1.0 (+https://elchat.io)'])->get($current);
            } catch (\Throwable $exception) {
                $firstError ??= $exception->getMessage();
                Log::warning("WebsiteIntelligenceService: échec fetch {$current}: {$exception->getMessage()}");

                continue;
            }

            if ($response->failed()) {
                $firstError ??= "Le site a répondu avec un statut {$response->status()}.";

                continue;
            }

            $body = $response->body();
            $bodies[] = $body;
            if (count($visited) < $maxPages) {
                foreach ($this->extractInternalLinks($body, $current) as $link) {
                    if (! isset($visited[$link])) {
                        $queue[] = $link;
                    }
                }
            }
        }

        if (! $bodies) {
            return $this->emptyResult($firstError ?: 'Site inaccessible.');
        }

        $rawHtml = implode("\n", $bodies);
        $html = mb_strtolower($rawHtml);

        return [
            'has_chatbot' => $this->containsAny($html, self::KNOWN_CHAT_WIDGET_MARKERS),
            'contact_form_only' => str_contains($html, '<form') && ! $this->containsAny($html, self::KNOWN_CHAT_WIDGET_MARKERS),
            'social_activity_score' => collect(self::SOCIAL_DOMAINS)->filter(fn ($domain) => str_contains($html, $domain))->count(),
            'has_competitor_solution' => $this->containsAny($html, self::KNOWN_CHAT_WIDGET_MARKERS),
            'page_title' => $this->extractTitle($rawHtml),
            'fetch_error' => $firstError,
            'pages_analyzed' => count($bodies),
            'icp_matches' => $this->extractIcpMatches($html, $icp),
        ];
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function extractTitle(string $html): ?string
    {
        return preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches) ? trim(strip_tags($matches[1])) : null;
    }

    private function emptyResult(string $error): array
    {
        return [
            'has_chatbot' => false, 'contact_form_only' => false, 'social_activity_score' => 0,
            'has_competitor_solution' => false, 'page_title' => null, 'fetch_error' => $error, 'pages_analyzed' => 0,
            'icp_matches' => [],
        ];
    }

    /** @return array<string, array<int, string>> */
    private function extractIcpMatches(string $html, array $icp): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($html)));
        $matches = [];

        foreach (['sector', 'company_type', 'location', 'needs', 'custom_criteria'] as $field) {
            $fieldMatches = [];
            foreach ($this->matchingTerms((string) ($icp[$field] ?? '')) as $term) {
                if (str_contains($text, $term)) {
                    $fieldMatches[] = $term;
                }
            }
            if ($fieldMatches) {
                $matches[$field] = array_values(array_unique($fieldMatches));
            }
        }

        return $matches;
    }

    /** @return string[] */
    private function matchingTerms(string $value): array
    {
        $value = mb_strtolower(trim($value));
        if ($value === '') {
            return [];
        }

        $stopWords = ['avec', 'dans', 'des', 'les', 'une', 'pour', 'site', 'web', 'public', 'moyen', 'possibilite', 'activité', 'activite'];
        $terms = [];
        foreach (preg_split('/[,;|]+/u', $value) ?: [] as $part) {
            $part = trim((string) preg_replace('/\s+/u', ' ', $part));
            if ($part === '') {
                continue;
            }
            if (mb_strlen($part) >= 5 && mb_strlen($part) <= 80) {
                $terms[] = $part;
            }
            foreach (preg_split('/[^\p{L}\p{N}]+/u', $part) ?: [] as $token) {
                $token = trim($token);
                if (mb_strlen($token) >= 5 && ! in_array($token, $stopWords, true)) {
                    $terms[] = $token;
                }
            }
        }

        return array_values(array_unique($terms));
    }

    /** @return string[] */
    private function extractInternalLinks(string $html, string $baseUrl): array
    {
        $baseHost = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
        $links = [];
        if (! preg_match_all('/<a[^>]+href=["\']([^"\']+)["\']/i', $html, $matches)) {
            return [];
        }

        foreach ($matches[1] as $href) {
            $href = trim(html_entity_decode($href));
            if ($href === '' || str_starts_with($href, '#') || str_starts_with(strtolower($href), 'mailto:')) {
                continue;
            }
            if (! preg_match('#^https?://#i', $href)) {
                $href = rtrim($baseUrl, '/').'/'.ltrim($href, '/');
            }
            $host = strtolower((string) parse_url($href, PHP_URL_HOST));
            if ($host !== $baseHost || ! filter_var($href, FILTER_VALIDATE_URL)) {
                continue;
            }
            $links[] = preg_replace('/#.*$/', '', $href);
        }

        return array_values(array_unique(array_filter($links)));
    }
}
