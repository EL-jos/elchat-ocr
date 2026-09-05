<?php

namespace App\Domain\Sales\Sources;

use App\Domain\Sales\Contracts\ProspectSourceInterface;
use App\Domain\Sales\ProspectingLocationResolver;
use App\Models\Conversation;
use App\Models\Site;
use Illuminate\Support\Collection;

class HerePlacesSource extends AbstractPlaceApiSource implements ProspectSourceInterface
{
    public function __construct(private readonly ProspectingLocationResolver $locations) {}

    public function key(): string
    {
        return 'here';
    }

    public function discover(Site $site, Conversation $conversation, array $icp, int $limit, array $options = []): Collection
    {
        $apiKey = (string) config('prospecting.here.api_key', '');
        if ($apiKey === '') {
            throw new \RuntimeException('La clé HERE Technologies n’est pas configurée côté ELChat.');
        }

        $location = trim((string) ($icp['location'] ?? ''));
        $resolved = $this->locations->resolve($location);
        if (! $resolved) {
            throw new \RuntimeException("La localisation ICP « {$location} » n’a pas pu être géocodée.");
        }

        $config = config('prospecting.here');
        $maxRequests = max(1, min(count($this->queryTerms($icp)), (int) ($options['max_requests_per_source'] ?? 3)));
        $candidates = collect();
        $bbox = implode(',', [$resolved['west'], $resolved['south'], $resolved['east'], $resolved['north']]);

        foreach ($this->queryTerms($icp, $maxRequests) as $query) {
            if ($candidates->count() >= $limit) {
                break;
            }

            $payload = $this->httpGet(
                (string) $config['endpoint'],
                [
                    'at' => $resolved['lat'].','.$resolved['lon'],
                    'in' => 'bbox:'.$bbox,
                    'q' => $query,
                    'limit' => min(100, max(1, $limit - $candidates->count())),
                    'lang' => 'fr-FR',
                    'apiKey' => $apiKey,
                ],
                (string) config('prospecting.http.user_agent'),
                (int) $config['timeout'],
                (int) $config['connect_timeout'],
                (int) $config['retries'],
            );

            foreach ($payload['items'] ?? [] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $name = $this->asString($item['title'] ?? null);
                if (! $name) {
                    continue;
                }
                $addressData = is_array($item['address'] ?? null) ? $item['address'] : [];
                $address = $this->asString($addressData['label'] ?? null);
                $contacts = is_array($item['contacts'] ?? null) ? ($item['contacts'][0] ?? []) : [];
                $phone = $this->contactValue($contacts['phone'] ?? null);
                $website = $this->contactValue($contacts['www'] ?? null);
                $email = $this->contactValue($contacts['email'] ?? null);
                $normalizedWebsite = $this->normalizeUrl($website);
                $categories = collect($item['categories'] ?? [])
                    ->map(fn ($category) => is_array($category) ? ($category['name'] ?? $category['id'] ?? null) : null)
                    ->filter()->values()->all();
                $sourceUrl = 'https://www.here.com/';
                $id = $this->asString($item['id'] ?? null);

                $candidates->push([
                    'name' => $name,
                    'company' => $name,
                    'website' => $normalizedWebsite,
                    'domain' => $normalizedWebsite ? parse_url($normalizedWebsite, PHP_URL_HOST) : null,
                    'email' => $email,
                    'phone' => $phone,
                    'location' => $this->asString($addressData['city'] ?? $addressData['district'] ?? null) ?: $location,
                    'address' => $address ?: null,
                    'sector' => $categories[0] ?? $query,
                    'external_key' => $id ? "here:{$id}" : null,
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
                        'provider' => 'here',
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

    private function contactValue(mixed $contacts): ?string
    {
        if (is_array($contacts)) {
            $first = $contacts[0] ?? null;

            return is_array($first) ? $this->asString($first['value'] ?? null) : $this->asString($first);
        }

        return $this->asString($contacts);
    }
}
