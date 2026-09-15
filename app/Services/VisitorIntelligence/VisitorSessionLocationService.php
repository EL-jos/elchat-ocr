<?php

namespace App\Services\VisitorIntelligence;

use App\Jobs\VisitorIntelligence\ResolveVisitorSessionLocationJob;
use App\Models\VisitorSession;
use GeoIp2\Database\Reader;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class VisitorSessionLocationService
{
    private ?Reader $geoReader = null;
    public function __construct(
        private readonly VisitorIntelligenceRealtimeService $realtime,
    ) {
    }

    public function dispatchIfNeeded(VisitorSession $session, ?string $ip): void
    {
        if (!config('visitor-intelligence.geo.enabled', true)) return;
        if ($session->country_code || $session->city) return;

        $status = trim((string) $session->location_status);
        if (in_array($status, ['ready', 'not_found'], true)) return;

        $queueStaleAfter = max(1, (int) config('visitor-intelligence.geo.queue_stale_after_seconds', 900));
        $staleBefore = now()->subSeconds($queueStaleAfter);
        if ($status === 'queued' && $session->location_resolved_at?->greaterThan($staleBefore)) return;

        $ip = trim((string) $ip);
        if (!$this->isPublicIp($ip)) {
            // A private address usually means the request came through a
            // reverse proxy whose forwarded headers were not trusted. Keep
            // this state retryable instead of permanently discarding geo data.
            if ($status !== 'ip_unavailable') {
                $session->forceFill([
                    'location_status' => 'ip_unavailable',
                    'location_resolved_at' => now(),
                ])->save();
            }
            return;
        }

        if ($status === 'failed' && $session->location_resolved_at) {
            $retryAfter = max(0, (int) config('visitor-intelligence.geo.retry_after_seconds', 300));
            if ($session->location_resolved_at->greaterThan(now()->subSeconds($retryAfter))) return;
        }

        // The conditional update prevents one browser batch and one rrweb
        // chunk arriving together from enqueueing duplicate lookups.
        $queued = VisitorSession::query()
            ->whereKey($session->id)
            ->where(function ($query) use ($staleBefore) {
                $query->whereNull('location_status')
                    ->orWhereIn('location_status', [
                        'pending',
                        'failed',
                        'ip_unavailable',
                        // Compatibility with rows written before
                        // ip_unavailable/not_found were distinguished.
                        'unavailable',
                    ])
                    ->orWhere(function ($query) use ($staleBefore) {
                        $query->where('location_status', 'queued')
                            ->where(function ($query) use ($staleBefore) {
                                $query->whereNull('location_resolved_at')
                                    ->orWhere('location_resolved_at', '<=', $staleBefore);
                            });
                    });
            })
            ->update([
                'location_status' => 'queued',
                'location_resolved_at' => now(),
            ]);

        if ($queued === 1) {
            try {
                // Keep the client IP out of the queue payload in clear text.
                ResolveVisitorSessionLocationJob::dispatch(
                    (string) $session->id,
                    Crypt::encryptString($ip),
                );
            } catch (Throwable $exception) {
                // A geo lookup is optional: a queue outage must never make
                // the tenant page or the existing tracker fail.
                VisitorSession::query()->whereKey($session->id)->update([
                    'location_status' => 'failed',
                    'location_resolved_at' => now(),
                ]);
                Log::warning('Visitor Intelligence location job dispatch failed.', [
                    'session_id' => (string) $session->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    public function resolve(string $sessionId, ?string $ip): void
    {
        $session = VisitorSession::query()->find($sessionId);
        if (!$session) return;

        $ip = trim((string) $ip);
        if (!$this->isPublicIp($ip)) {
            $this->mark($session, 'ip_unavailable');
            return;
        }

        try {
            $location = $this->lookup($ip);
            if (!$location) {
                $this->mark($session, 'not_found');
                return;
            }

            $session->forceFill([
                'country_code' => $location['country_code'],
                'country_name' => $location['country_name'],
                'city' => $location['city'],
                'location_status' => 'ready',
                'location_resolved_at' => now(),
            ])->save();

            $this->realtime->publish((string) $session->site_id, 'session_location_updated', [
                'session_id' => (string) $session->id,
                'country_code' => $session->country_code,
                'country_name' => $session->country_name,
                'city' => $session->city,
                'location_status' => $session->location_status,
                'location_resolved_at' => $session->location_resolved_at?->toISOString(),
            ]);
        } catch (Throwable $exception) {
            $this->mark($session, 'failed');
            Log::warning('Visitor Intelligence location lookup failed.', [
                'session_id' => (string) $session->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function lookup(string $ip): ?array
    {
        /*
         * 1. Source principale : IPWhois
         */
        try {
            $endpoint = (string) config(
                'visitor-intelligence.geo.endpoint',
                'https://ipwho.is/{ip}'
            );

            $url = str_contains($endpoint, '{ip}')
                ? str_replace('{ip}', rawurlencode($ip), $endpoint)
                : rtrim($endpoint, '/').'/'.rawurlencode($ip);

            $response = Http::connectTimeout(
                max(1, (int) config('visitor-intelligence.geo.connect_timeout', 2))
            )
                ->timeout(
                    max(2, (int) config('visitor-intelligence.geo.timeout', 5))
                )
                ->acceptJson()
                ->get($url);

            $response->throw();

            if ($response->json('success') !== false) {
                $countryCode = strtoupper(
                    trim((string) $response->json('country_code'))
                );

                $countryName = $this->text(
                    $response->json('country')
                );

                $city = $this->text(
                    $response->json('city')
                );

                if (!preg_match('/^[A-Z]{2}$/', $countryCode)) {
                    $countryCode = null;
                }

                if ($countryCode || $countryName || $city) {
                    return [
                        'country_code' => $countryCode,
                        'country_name' => $countryName,
                        'city' => $city,
                    ];
                }
            }
        } catch (Throwable $exception) {
            Log::warning('Visitor Intelligence primary geo provider failed, falling back to GeoLite2.', [
                'ip' => $ip,
                'error' => $exception->getMessage(),
            ]);
        }

        /*
         * 2. Fallback : GeoLite2 local
         */
        if (config('visitor-intelligence.geo.fallback_enabled', true)) {
            return $this->lookupWithGeoLite2($ip);
        }

        return null;
    }

    private function lookupWithGeoLite2(string $ip): ?array
    {
        try {
            $databasePath = (string) config(
                'visitor-intelligence.geo.database_path',
                public_path('tools/geoip/GeoLite2-City.mmdb')
            );

            if (!is_file($databasePath)) {
                Log::warning('Visitor Intelligence GeoLite2 database not found.', [
                    'path' => $databasePath,
                ]);

                return null;
            }

            $this->geoReader ??= new Reader($databasePath);

            $record = $this->geoReader->city($ip);

            $countryCode = strtoupper(
                trim((string) $record->country->isoCode)
            );

            $countryName = $this->text(
                $record->country->name
            );

            $city = $this->text(
                $record->city->name
            );

            if (!preg_match('/^[A-Z]{2}$/', $countryCode)) {
                $countryCode = null;
            }

            if (!$countryCode && !$countryName && !$city) {
                return null;
            }

            return [
                'country_code' => $countryCode,
                'country_name' => $countryName,
                'city' => $city,
            ];
        } catch (Throwable $exception) {
            Log::warning('Visitor Intelligence GeoLite2 lookup failed.', [
                'ip' => $ip,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function mark(VisitorSession $session, string $status): void
    {
        $session->forceFill([
            'location_status' => $status,
            'location_resolved_at' => now(),
        ])->save();
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function text(mixed $value): ?string
    {
        if (!is_scalar($value)) return null;
        $value = trim(strip_tags((string) $value));
        return $value === '' ? null : mb_substr($value, 0, 128);
    }
}
