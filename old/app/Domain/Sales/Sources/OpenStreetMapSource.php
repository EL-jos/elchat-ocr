<?php

namespace App\Domain\Sales\Sources;

use App\Domain\Sales\Contracts\ProspectSourceInterface;
use App\Domain\Sales\ProspectingLocationResolver;
use App\Models\Conversation;
use App\Models\Site;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class OpenStreetMapSource implements ProspectSourceInterface
{
    public function __construct(
        private readonly OpenStreetMapQueryBuilder $queryBuilder,
        private readonly ?ProspectingLocationResolver $locationResolver = null,
    ) {}

    public function key(): string
    {
        return 'openstreetmap';
    }

    public function discover(Site $site, Conversation $conversation, array $icp, int $limit, array $options = []): Collection
    {
        $queryIcp = $icp;
        if ($this->locationResolver) {
            $resolvedLocation = $this->locationResolver->resolve((string) ($icp['location'] ?? ''));
            if (! $resolvedLocation) {
                Log::warning('OpenStreetMap source: localisation ICP non résolue, source ignorée', [
                    'location' => $icp['location'] ?? null,
                ]);

                return collect();
            }

            // Le prototype interroge Overpass sur la bounding box Nominatim.
            // Cette forme est nettement moins coûteuse que area.searchArea,
            // notamment pour une région ou un pays entier.
            $queryIcp['_osm_bbox'] = [
                'south' => $resolvedLocation['south'],
                'west' => $resolvedLocation['west'],
                'north' => $resolvedLocation['north'],
                'east' => $resolvedLocation['east'],
            ];
        }

        $query = $this->queryBuilder->build([
            ...$queryIcp,
            '_overpass_query_timeout' => (int) ($options['query_timeout'] ?? config('prospecting.openstreetmap.query_timeout', 18)),
        ], $limit);
        if (! $query) {
            return collect();
        }

        $cacheKey = 'sales-hunter:osm:'.hash('sha256', $query);
        $ttl = (int) ($options['cache_ttl'] ?? config('prospecting.openstreetmap.cache_ttl', 21600));
        $cooldownKey = $cacheKey.':unavailable';
        if (Cache::has($cooldownKey)) {
            Log::info('OpenStreetMap source: endpoint temporairement en cooldown', ['cache_key' => $cacheKey]);

            return collect();
        }

        try {
            $payload = Cache::remember($cacheKey, now()->addSeconds(max(60, $ttl)), fn () => $this->request($query, $options));
        } catch (Throwable $exception) {
            Cache::put(
                $cooldownKey,
                true,
                now()->addSeconds(max(30, (int) ($options['cooldown_seconds'] ?? config('prospecting.openstreetmap.cooldown_seconds', 300)))),
            );

            throw $exception;
        }

        return collect($payload['elements'] ?? [])
            ->map(fn (array $element) => $this->mapElement($element, $icp))
            ->filter(fn (?array $candidate) => $candidate !== null)
            ->values();
    }

    private function request(string $query, array $options): array
    {
        $endpoints = $options['endpoints'] ?? config('prospecting.openstreetmap.endpoints', []);
        $endpoints = is_array($endpoints) ? array_values(array_filter($endpoints)) : [];
        $lastError = null;
        $timeout = max(5, min(60, (int) ($options['timeout'] ?? config('prospecting.openstreetmap.timeout', 18))));
        $connectTimeout = max(2, min($timeout, (int) ($options['connect_timeout'] ?? config('prospecting.openstreetmap.connect_timeout', 5))));
        $retries = max(0, min(4, (int) ($options['retries'] ?? config('prospecting.openstreetmap.retries', 3))));
        $maxDuration = max(30, min(240, (int) ($options['max_duration_seconds'] ?? config('prospecting.openstreetmap.max_duration_seconds', 150))));
        $deadline = microtime(true) + $maxDuration;
        $attempts = $retries + 1;

        foreach ($endpoints as $endpoint) {
            $remainingSeconds = (int) floor($deadline - microtime(true));
            if ($remainingSeconds < 5) {
                break;
            }
            $requestTimeout = min($timeout, max(5, intdiv($remainingSeconds, $attempts)));
            $requestConnectTimeout = min($connectTimeout, $requestTimeout);

            try {
                $lock = Cache::lock('sales-hunter:osm:request-rate', 30);
                $response = $lock->block(5, function () use ($endpoint, $query, $options, $requestTimeout, $requestConnectTimeout, $retries) {
                    $minIntervalMs = max(0, (int) ($options['min_interval_ms'] ?? config('prospecting.openstreetmap.min_interval_ms', 1100)));
                    $lastRequest = (float) Cache::get('sales-hunter:osm:last-request-at', 0);
                    $remainingMs = $minIntervalMs - (int) round((microtime(true) - $lastRequest) * 1000);
                    if ($remainingMs > 0) {
                        usleep($remainingMs * 1000);
                    }
                    Cache::put('sales-hunter:osm:last-request-at', microtime(true), now()->addMinute());

                    return Http::withBody($query, 'text/plain')
                        ->acceptJson()
                        ->connectTimeout($requestConnectTimeout)
                        ->timeout($requestTimeout)
                        ->retry($retries, 500)
                        ->withHeaders(['User-Agent' => config('prospecting.http.user_agent')])
                        ->post($endpoint);
                });

                if ($response->successful()) {
                    $payload = $response->json();

                    if (! is_array($payload)) {
                        throw new \RuntimeException("Réponse JSON invalide depuis {$endpoint}");
                    }

                    return $payload;
                }
                $lastError = "HTTP {$response->status()} depuis {$endpoint}";
                $body = trim((string) preg_replace('/\\s+/u', ' ', $response->body()));
                Log::warning('OpenStreetMap source: endpoint en échec', [
                    'endpoint' => $endpoint,
                    'status' => $response->status(),
                    'response' => $body !== '' ? mb_substr($body, 0, 1000) : null,
                ]);
            } catch (Throwable $exception) {
                $lastError = $exception->getMessage();
                Log::warning('OpenStreetMap source: requête impossible', ['endpoint' => $endpoint, 'error' => $lastError]);
            }
        }

        if ($lastError) {
            throw new \RuntimeException('Tous les endpoints Overpass sont temporairement indisponibles : '.$lastError);
        }

        return [];
    }

    private function mapElement(array $element, array $icp): ?array
    {
        $tags = $element['tags'] ?? [];
        $name = trim((string) ($tags['name'] ?? $tags['brand'] ?? ''));
        if ($name === '') {
            return null;
        }

        $website = $tags['website'] ?? $tags['contact:website'] ?? null;
        $website = $this->normalizeUrl($website);
        $type = $element['type'] ?? 'node';
        $id = $element['id'] ?? null;
        $category = $tags['amenity'] ?? $tags['shop'] ?? $tags['office'] ?? $tags['craft'] ?? $tags['tourism'] ?? ($icp['sector'] ?? null);
        $location = $tags['addr:city'] ?? $tags['addr:place'] ?? ($icp['location'] ?? null);
        $address = trim(implode(' ', array_filter([
            $tags['addr:housenumber'] ?? null,
            $tags['addr:street'] ?? null,
            $tags['addr:postcode'] ?? null,
            $location,
        ])));
        $otherContact = collect([
            isset($tags['contact:facebook']) ? 'Facebook : '.$tags['contact:facebook'] : null,
            isset($tags['contact:instagram']) ? 'Instagram : '.$tags['contact:instagram'] : null,
            isset($tags['contact:whatsapp']) ? 'WhatsApp : '.$tags['contact:whatsapp'] : null,
            isset($tags['opening_hours']) ? 'Horaires : '.$tags['opening_hours'] : null,
        ])->filter()->implode(' · ');

        return [
            'name' => $name,
            'company' => $name,
            'website' => $website,
            'domain' => $website ? parse_url($website, PHP_URL_HOST) : null,
            'email' => $tags['email'] ?? $tags['contact:email'] ?? null,
            'phone' => $tags['phone'] ?? $tags['contact:phone'] ?? null,
            'location' => $location,
            'address' => $address !== '' ? $address : null,
            'sector' => $category,
            'other_contact' => $otherContact !== '' ? $otherContact : null,
            'external_key' => $id ? "osm:{$type}:{$id}" : null,
            'source_url' => $id ? "https://www.openstreetmap.org/{$type}/{$id}" : 'https://www.openstreetmap.org',
            'enrichment_data' => [
                'discovery' => [
                    'prototype_scoring' => [
                        'sector_matched' => true,
                        'location_matched' => true,
                        'needs_points' => 5,
                    ],
                ],
            ],
            'evidence' => [[
                'type' => 'observation', 'field' => 'osm_tags', 'value' => $tags,
                'source_url' => $id ? "https://www.openstreetmap.org/{$type}/{$id}" : null,
                'confidence' => 1.0, 'metadata' => ['attribution' => '© OpenStreetMap contributors', 'license' => 'ODbL'],
            ]],
        ];
    }

    private function normalizeUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }
        $url = trim($url);
        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            $url = "https://{$url}";
        }

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }
}
