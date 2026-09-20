<?php

namespace App\Services\VisitorIntelligence;

use Illuminate\Support\Str;

/**
 * Resolve a first-touch acquisition attribution for a Visitor Intelligence
 * session. The browser only supplies observable hints; the server owns the
 * normalization and the final classification.
 */
final class VisitorAcquisitionAttributionService
{
    private const SOURCE_TYPES = [
        'direct', 'organic', 'paid', 'social', 'email', 'ai', 'referral', 'campaign', 'other',
    ];

    /** @return array<string, string|null> */
    public function resolve(array $metadata): array
    {
        $pageUrl = $this->safeUrl($metadata['page_url'] ?? null);
        $referrer = $this->safeUrl($metadata['referrer'] ?? null);
        $pageHost = $this->host($pageUrl);
        $referrerHost = $this->host($referrer);
        $externalReferrer = $referrerHost !== null
            && ($pageHost === null || !$this->sameHost($pageHost, $referrerHost));

        $sourceHint = $this->token($metadata['utm_source'] ?? $metadata['source'] ?? null, 64);
        $mediumHint = $this->token($metadata['utm_medium'] ?? $metadata['medium'] ?? null, 64);
        $campaign = $this->text($metadata['utm_campaign'] ?? $metadata['campaign'] ?? null, 255);
        $term = $this->text($metadata['utm_term'] ?? $metadata['term'] ?? null, 255);
        $content = $this->text($metadata['utm_content'] ?? $metadata['content'] ?? null, 255);
        $platform = $this->text($metadata['utm_source_platform'] ?? $metadata['source_platform'] ?? null, 64);
        $clickNetwork = $this->clickNetwork($metadata, $pageUrl);
        $hasExplicitCampaignSignal = $sourceHint !== null
            || $mediumHint !== null
            || $campaign !== null
            || $term !== null
            || $content !== null;

        // Explicit campaign data is the strongest signal. A referrer is only
        // consulted when the landing URL did not carry campaign information;
        // this avoids a stale referrer overriding a deliberate UTM campaign.
        $aiSource = $this->knownAiSource($sourceHint)
            ?? (!$hasExplicitCampaignSignal ? $this->knownAiSource($referrerHost) : null);
        $searchSource = $this->knownSearchSource($sourceHint)
            ?? (!$hasExplicitCampaignSignal ? $this->knownSearchSource($referrerHost) : null);
        $socialSource = $this->knownSocialSource($sourceHint)
            ?? (!$hasExplicitCampaignSignal ? $this->knownSocialSource($referrerHost) : null);

        $source = $sourceHint;
        $medium = $mediumHint;
        $sourceType = null;
        $confidence = 'medium';

        if ($aiSource !== null) {
            $source = $aiSource;
            $medium ??= 'ai_referral';
            $sourceType = 'ai';
            $platform ??= $aiSource;
            $confidence = $sourceHint !== null || $externalReferrer ? 'high' : 'medium';
        } elseif ($this->isEmailMedium($mediumHint) || $this->isEmailSource($sourceHint)) {
            $source = $source ?: 'email';
            $medium = $medium ?: 'email';
            $sourceType = 'email';
            $confidence = 'high';
        } elseif ($clickNetwork !== null) {
            $source = $source ?: $clickNetwork;
            $medium = $medium ?: 'cpc';
            $sourceType = 'paid';
            $platform ??= $clickNetwork;
            $confidence = 'high';
        } elseif ($this->isPaidMedium($mediumHint)) {
            $source = $source ?: 'paid';
            $medium = $medium ?: 'paid';
            $sourceType = 'paid';
            $confidence = 'high';
        } elseif ($searchSource !== null || $this->isOrganicMedium($mediumHint)) {
            $source = $searchSource ?? $source ?? 'search';
            $medium = $medium ?: 'organic';
            $sourceType = 'organic';
            $confidence = $searchSource !== null || $sourceHint !== null ? 'high' : 'medium';
        } elseif ($socialSource !== null || $this->isSocialMedium($mediumHint)) {
            $source = $socialSource ?? $source ?? 'social';
            $medium = $medium ?: 'social';
            $sourceType = $this->isPaidMedium($mediumHint) ? 'paid' : 'social';
            $confidence = $socialSource !== null || $sourceHint !== null ? 'high' : 'medium';
        } elseif ($externalReferrer) {
            $source = $source ?: $this->fallbackHostSource($referrerHost);
            $medium = $medium ?: 'referral';
            $sourceType = 'referral';
            $confidence = 'high';
        } elseif ($source !== null || $medium !== null || $campaign !== null) {
            $source = $source ?: 'campaign';
            $medium = $medium ?: 'campaign';
            $sourceType = 'campaign';
            $confidence = 'medium';
        } else {
            $source = 'direct';
            $medium = 'none';
            $sourceType = 'direct';
            $confidence = 'high';
        }

        $sourceType = in_array($sourceType, self::SOURCE_TYPES, true) ? $sourceType : 'other';
        $source = $this->token($source, 64) ?: 'other';
        $medium = $this->token($medium, 64) ?: 'unknown';

        return [
            'source' => $source,
            'medium' => $medium,
            'campaign' => $campaign,
            'term' => $term,
            'content' => $content,
            'source_type' => $sourceType,
            'platform' => $platform ? $this->token($platform, 64) : null,
            'referrer' => $referrer ?: null,
            'confidence' => $confidence,
        ];
    }

    private function knownAiSource(?string $value): ?string
    {
        return $this->knownSource($value, (array) config('visitor-intelligence.attribution.ai_domains', []), [
            'chatgpt' => 'chatgpt', 'openai' => 'chatgpt', 'perplexity' => 'perplexity',
            'claude' => 'claude', 'anthropic' => 'claude', 'gemini' => 'gemini',
            'copilot' => 'copilot', 'you.com' => 'you', 'phind' => 'phind',
            'poe' => 'poe', 'grok' => 'grok', 'meta.ai' => 'meta_ai',
            'mistral' => 'mistral', 'deepseek' => 'deepseek', 'character.ai' => 'character_ai',
        ]);
    }

    private function knownSearchSource(?string $value): ?string
    {
        return $this->knownSource($value, (array) config('visitor-intelligence.attribution.search_domains', []), [
            'google' => 'google', 'bing' => 'bing', 'yahoo' => 'yahoo',
            'duckduckgo' => 'duckduckgo', 'ecosia' => 'ecosia', 'yandex' => 'yandex',
            'baidu' => 'baidu', 'brave' => 'brave',
        ]);
    }

    private function knownSocialSource(?string $value): ?string
    {
        return $this->knownSource($value, (array) config('visitor-intelligence.attribution.social_domains', []), [
            'facebook' => 'facebook', 'instagram' => 'instagram', 'linkedin' => 'linkedin',
            'twitter' => 'x', 'x.com' => 'x', 't.co' => 'x', 'youtube' => 'youtube',
            'youtu.be' => 'youtube', 'tiktok' => 'tiktok', 'pinterest' => 'pinterest',
            'reddit' => 'reddit', 'threads' => 'threads',
        ]);
    }

    /** @param array<int, string> $domains @param array<string, string> $aliases */
    private function knownSource(?string $value, array $domains, array $aliases): ?string
    {
        if (!$value) return null;
        $candidate = strtolower(trim($value));
        $candidate = preg_replace('/^https?:\/\//', '', $candidate) ?: $candidate;
        $candidate = trim(explode('/', $candidate, 2)[0]);
        $candidate = preg_replace('/^www\./', '', $candidate) ?: $candidate;

        foreach ($aliases as $needle => $source) {
            if ($candidate === $needle || str_starts_with($candidate, $needle.'.') || str_contains($candidate, '.'.$needle.'.')) {
                return $source;
            }
        }
        foreach ($domains as $domain) {
            $domain = strtolower(trim((string) $domain));
            if ($domain === '') continue;
            if ($domain === $candidate || str_ends_with($candidate, '.'.$domain)) {
                return $this->fallbackHostSource($candidate);
            }
            if (str_ends_with($domain, '.') && str_starts_with($candidate, $domain)) {
                return $this->fallbackHostSource($candidate);
            }
        }
        return null;
    }

    private function clickNetwork(array $metadata, ?string $pageUrl): ?string
    {
        $clientNetwork = $this->token($metadata['ad_click_network'] ?? null, 64);
        $networks = array_map(
            fn (mixed $network): string => (string) $network,
            array_values((array) config('visitor-intelligence.attribution.paid_click_parameters', [])),
        );
        if ($clientNetwork !== null && in_array($clientNetwork, $networks, true)) return $clientNetwork;

        $query = [];
        if ($pageUrl) parse_str((string) parse_url($pageUrl, PHP_URL_QUERY), $query);
        foreach ((array) config('visitor-intelligence.attribution.paid_click_parameters', []) as $parameter => $network) {
            if (array_key_exists($parameter, $metadata) || array_key_exists($parameter, $query)) {
                return $this->token((string) $network, 64);
            }
        }
        return null;
    }

    private function isPaidMedium(?string $medium): bool
    {
        return $medium !== null && in_array(strtolower($medium), [
            'cpc', 'ppc', 'paid', 'paidsearch', 'paid_search', 'display', 'banner',
            'paid_social', 'paidsocial', 'social_paid', 'programmatic',
        ], true);
    }

    private function isOrganicMedium(?string $medium): bool
    {
        return $medium !== null && in_array(strtolower($medium), ['organic', 'seo', 'organic_search'], true);
    }

    private function isSocialMedium(?string $medium): bool
    {
        return $medium !== null && in_array(strtolower($medium), ['social', 'social_media', 'social-organic'], true);
    }

    private function isEmailMedium(?string $medium): bool
    {
        return $medium !== null && in_array(strtolower($medium), ['email', 'newsletter', 'mail'], true);
    }

    private function isEmailSource(?string $source): bool
    {
        return $source !== null && in_array(strtolower($source), ['email', 'newsletter', 'mail'], true);
    }

    private function fallbackHostSource(?string $host): string
    {
        $host = strtolower(trim((string) $host));
        $host = preg_replace('/^www\./', '', $host) ?: $host;
        return $this->token($host, 64) ?: 'referral';
    }

    private function sameHost(string $left, string $right): bool
    {
        return $this->normalizeHost($left) === $this->normalizeHost($right);
    }

    private function host(?string $url): ?string
    {
        if (!$url) return null;
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) && $host !== '' ? $this->normalizeHost($host) : null;
    }

    private function normalizeHost(string $host): string
    {
        return strtolower(preg_replace('/^www\./', '', trim($host)) ?: trim($host));
    }

    private function safeUrl(mixed $value): ?string
    {
        if (!is_scalar($value)) return null;
        $parts = parse_url(trim((string) $value));
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return null;
        if (!in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) return null;
        return Str::limit(
            $parts['scheme'].'://'.$parts['host'].($parts['path'] ?? ''),
            2048,
            '',
        );
    }

    private function token(mixed $value, int $limit): ?string
    {
        if (!is_scalar($value)) return null;
        $value = strtolower(trim(strip_tags((string) $value)));
        $value = preg_replace('/[^a-z0-9._-]+/i', '-', $value) ?: '';
        $value = trim($value, '-._');
        return $value === '' ? null : Str::limit($value, $limit, '');
    }

    private function text(mixed $value, int $limit): ?string
    {
        if (!is_scalar($value)) return null;
        $value = trim(strip_tags((string) $value));
        return $value === '' ? null : Str::limit($value, $limit, '');
    }
}
