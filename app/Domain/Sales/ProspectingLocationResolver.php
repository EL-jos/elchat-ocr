<?php

namespace App\Domain\Sales;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Résout une zone ICP en coordonnées et emprise réutilisables par les sources géographiques. */
class ProspectingLocationResolver
{
    public function resolve(string $location): ?array
    {
        $location = trim($location);
        if ($location === '') {
            return null;
        }

        $cacheKey = 'sales-hunter:geocode:'.hash('sha256', mb_strtolower($location));

        return Cache::remember(
            $cacheKey,
            now()->addSeconds(max(300, (int) config('prospecting.geocoding.cache_ttl', 86400))),
            fn () => $this->fetch($location),
        );
    }

    private function fetch(string $location): ?array
    {
        try {
            $response = Http::connectTimeout(max(2, (int) config('prospecting.geocoding.connect_timeout', 4)))
                ->timeout(max(5, (int) config('prospecting.geocoding.timeout', 10)))
                ->withHeaders(['User-Agent' => config('prospecting.http.user_agent')])
                ->get(config('prospecting.geocoding.endpoint'), [
                    'q' => $location,
                    'format' => 'jsonv2',
                    'limit' => 1,
                    'addressdetails' => 1,
                ]);
        } catch (\Throwable $exception) {
            Log::warning('Prospecting geocoding: requête impossible', [
                'location' => $location,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('Prospecting geocoding: réponse invalide', [
                'location' => $location,
                'status' => $response->status(),
            ]);

            return null;
        }

        $place = $response->json()[0] ?? null;
        if (! is_array($place) || ! is_numeric($place['lat'] ?? null) || ! is_numeric($place['lon'] ?? null)) {
            return null;
        }

        $bbox = array_values(array_map('floatval', $place['boundingbox'] ?? []));
        if (count($bbox) !== 4) {
            $lat = (float) $place['lat'];
            $lon = (float) $place['lon'];
            $bbox = [$lat - 0.1, $lat + 0.1, $lon - 0.1, $lon + 0.1];
        }

        return [
            'label' => $place['display_name'] ?? $location,
            'lat' => (float) $place['lat'],
            'lon' => (float) $place['lon'],
            'south' => $bbox[0],
            'north' => $bbox[1],
            'west' => $bbox[2],
            'east' => $bbox[3],
            'country_code' => strtoupper((string) ($place['address']['country_code'] ?? '')),
        ];
    }
}
