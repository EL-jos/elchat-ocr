<?php

namespace App\Domain\Sales\Sources;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/** Comportement partagé des sources de lieux pilotées par une clé ELChat. */
abstract class AbstractPlaceApiSource
{
    /** @return array<int, string> */
    protected function queryTerms(array $icp, int $maxTerms = 3): array
    {
        $values = [
            $icp['sector'] ?? null,
            $icp['company_type'] ?? null,
            $icp['needs'] ?? null,
        ];
        $terms = [];

        foreach ($values as $value) {
            foreach (preg_split('/[,;|]+/u', mb_strtolower((string) $value)) ?: [] as $term) {
                $term = trim((string) preg_replace('/\s+/u', ' ', $term));
                if ($term !== '' && mb_strlen($term) <= 80) {
                    $terms[] = $term;
                }
            }
        }

        $terms = array_values(array_unique($terms));

        return array_slice($terms ?: ['business'], 0, max(1, $maxTerms));
    }

    protected function httpGet(string $endpoint, array $query, string $userAgent, int $timeout, int $connectTimeout, int $retries, array $headers = []): array
    {
        $response = Http::connectTimeout(max(2, $connectTimeout))
            ->timeout(max(5, $timeout))
            ->retry(max(0, min(3, $retries)), 400)
            ->withHeaders(array_merge(['User-Agent' => $userAgent, 'Accept' => 'application/json'], $headers))
            ->get($endpoint, $query);

        if ($response->failed()) {
            throw new \RuntimeException("HTTP {$response->status()} depuis {$endpoint}");
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    protected function normalizeUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }
        $url = trim($url);
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    protected function asString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    protected function uniqueCandidates(Collection $candidates, int $limit): Collection
    {
        return $candidates
            ->filter(fn ($candidate) => is_array($candidate) && ! empty($candidate['name']))
            ->unique(fn (array $candidate) => $candidate['external_key'] ?? $candidate['domain'] ?? mb_strtolower($candidate['name']))
            ->take(max(1, $limit))
            ->values();
    }

    /** @param array<string, mixed> $value */
    protected function evidence(string $sourceUrl, array $value, float $confidence = 0.8): array
    {
        return [[
            'type' => 'observation',
            'field' => 'provider_place_data',
            'value' => $value,
            'source_url' => $sourceUrl,
            'confidence' => $confidence,
        ]];
    }
}
