<?php

namespace App\Domain\Sales\Sources;

use App\Domain\Sales\Contracts\ProspectSourceInterface;
use App\Domain\Sales\ProspectingLocationResolver;
use App\Models\Conversation;
use App\Models\Site;
use Illuminate\Support\Collection;

class TomTomPlacesSource extends AbstractPlaceApiSource implements ProspectSourceInterface
{
    public function __construct(private readonly ProspectingLocationResolver $locations) {}

    public function key(): string
    {
        return 'tomtom';
    }

    public function discover(Site $site, Conversation $conversation, array $icp, int $limit, array $options = []): Collection
    {
        $apiKey = (string) config('prospecting.tomtom.api_key', '');
        if ($apiKey === '') {
            throw new \RuntimeException('La clé TomTom Developer n’est pas configurée côté ELChat.');
        }

        $location = trim((string) ($icp['location'] ?? ''));
        $resolved = $this->locations->resolve($location);
        if (! $resolved) {
            throw new \RuntimeException("La localisation ICP « {$location} » n’a pas pu être géocodée.");
        }

        $config = config('prospecting.tomtom');
        $maxRequests = max(1, min(count($this->queryTerms($icp)), (int) ($options['max_requests_per_source'] ?? 3)));
        $candidates = collect();

        foreach ($this->queryTerms($icp, $maxRequests) as $query) {
            if ($candidates->count() >= $limit) {
                break;
            }

            $payload = $this->httpGet(
                rtrim((string) $config['endpoint'], '/').'/'.rawurlencode($query).'.json',
                [
                    'lat' => $resolved['lat'],
                    'lon' => $resolved['lon'],
                    'radius' => max(1, min(100000, (int) $config['radius'])),
                    'limit' => min(100, max(1, $limit - $candidates->count())),
                    'language' => 'fr-FR',
                    'key' => $apiKey,
                ],
                (string) config('prospecting.http.user_agent'),
                (int) $config['timeout'],
                (int) $config['connect_timeout'],
                (int) $config['retries'],
            );

            foreach ($payload['results'] ?? [] as $result) {
                if (! is_array($result)) {
                    continue;
                }
                $poi = is_array($result['poi'] ?? null) ? $result['poi'] : [];
                $addressData = is_array($result['address'] ?? null) ? $result['address'] : [];
                $name = $this->asString($poi['name'] ?? null);
                if (! $name) {
                    continue;
                }
                $website = $this->normalizeUrl($poi['url'] ?? null);
                $categories = array_values(array_filter(array_map('strval', $poi['categories'] ?? [])));
                $id = $this->asString($result['id'] ?? null);
                $sourceUrl = 'https://www.tomtom.com/';

                $candidates->push([
                    'name' => $name,
                    'company' => $name,
                    'website' => $website,
                    'domain' => $website ? parse_url($website, PHP_URL_HOST) : null,
                    'email' => null,
                    'phone' => $this->asString($poi['phone'] ?? null),
                    'location' => $this->asString($addressData['localName'] ?? $addressData['municipality'] ?? null) ?: $location,
                    'address' => $this->asString($addressData['freeformAddress'] ?? null),
                    'sector' => $categories[0] ?? $query,
                    'external_key' => $id ? "tomtom:{$id}" : null,
                    'source_url' => $sourceUrl,
                    'enrichment_data' => [
                        'discovery' => [
                            'prototype_scoring' => [
                                'sector_matched' => true,
                                'location_matched' => true,
                                'needs_points' => 5,
                            ],
                        ],
                    ],
                    'evidence' => $this->evidence($sourceUrl, [
                        'provider' => 'tomtom',
                        'provider_id' => $id,
                        'name' => $name,
                        'categories' => $categories,
                        'address' => $addressData['freeformAddress'] ?? null,
                    ]),
                ]);
            }
        }

        return $this->uniqueCandidates($candidates, $limit);
    }
}
