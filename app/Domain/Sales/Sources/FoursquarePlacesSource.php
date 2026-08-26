<?php

namespace App\Domain\Sales\Sources;

use App\Domain\Sales\Contracts\ProspectSourceInterface;
use App\Models\Conversation;
use App\Models\Site;
use Illuminate\Support\Collection;

class FoursquarePlacesSource extends AbstractPlaceApiSource implements ProspectSourceInterface
{
    public function key(): string
    {
        return 'foursquare';
    }

    public function discover(Site $site, Conversation $conversation, array $icp, int $limit, array $options = []): Collection
    {
        $apiKey = (string) config('prospecting.foursquare.api_key', '');
        if ($apiKey === '') {
            throw new \RuntimeException('La clé Foursquare Places n’est pas configurée côté ELChat.');
        }

        $location = trim((string) ($icp['location'] ?? ''));
        if ($location === '') {
            return collect();
        }

        $config = config('prospecting.foursquare');
        $maxRequests = max(1, min(count($this->queryTerms($icp)), (int) ($options['max_requests_per_source'] ?? 3)));
        $candidates = collect();

        foreach ($this->queryTerms($icp, $maxRequests) as $query) {
            if ($candidates->count() >= $limit) {
                break;
            }

            $payload = $this->httpGet(
                (string) $config['endpoint'],
                [
                    'query' => $query,
                    'near' => $location,
                    'limit' => min(50, max(1, $limit - $candidates->count())),
                    'fields' => 'fsq_id,name,location,website,tel,email,categories',
                ],
                (string) config('prospecting.http.user_agent'),
                (int) $config['timeout'],
                (int) $config['connect_timeout'],
                (int) $config['retries'],
                ['Authorization' => 'Bearer '.$apiKey, 'X-Places-Api-Version' => $config['api_version']],
            );

            foreach (($payload['results'] ?? $payload['places'] ?? []) as $place) {
                if (! is_array($place)) {
                    continue;
                }
                $id = $this->asString($place['fsq_id'] ?? $place['id'] ?? null);
                $name = $this->asString($place['name'] ?? null);
                if (! $name) {
                    continue;
                }
                $locationData = is_array($place['location'] ?? null) ? $place['location'] : [];
                $address = $this->asString($locationData['formatted_address'] ?? null)
                    ?? trim(implode(', ', array_filter([
                        $locationData['address'] ?? null,
                        $locationData['locality'] ?? null,
                        $locationData['region'] ?? null,
                        $locationData['postcode'] ?? null,
                    ])));
                $website = $this->normalizeUrl($place['website'] ?? null);
                $categories = collect($place['categories'] ?? [])
                    ->map(fn ($category) => is_array($category) ? ($category['name'] ?? null) : null)
                    ->filter()->values()->all();
                $sourceUrl = 'https://foursquare.com/';

                $candidates->push([
                    'name' => $name,
                    'company' => $name,
                    'website' => $website,
                    'domain' => $website ? parse_url($website, PHP_URL_HOST) : null,
                    'email' => $this->asString($place['email'] ?? null),
                    'phone' => $this->asString($place['tel'] ?? null),
                    'location' => $this->asString($locationData['locality'] ?? null) ?: $location,
                    'address' => $address ?: null,
                    'sector' => $categories[0] ?? $query,
                    'external_key' => $id ? "foursquare:{$id}" : null,
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
                        'provider' => 'foursquare',
                        'provider_id' => $id,
                        'name' => $name,
                        'categories' => $categories,
                        'address' => $address,
                    ]),
                ]);
            }
        }

        return $this->uniqueCandidates($candidates, $limit);
    }
}
