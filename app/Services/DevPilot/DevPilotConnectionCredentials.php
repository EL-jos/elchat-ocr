<?php

namespace App\Services\DevPilot;

use App\Models\DevPilot\DevPilotConnection;
use RuntimeException;

/** Binds stored secrets to a single remote endpoint and strips metadata before use. */
final class DevPilotConnectionCredentials
{
    public static function bind(string $siteId, array $attributes, array $credentials): array
    {
        $credentials['bound_to'] = self::fingerprint($siteId, $attributes);
        return $credentials;
    }

    public static function verify(DevPilotConnection $connection, array $credentials): array
    {
        if (array_key_exists('bound_to', $credentials)) {
            $expected = self::fingerprint((string) $connection->site_id, $connection->getAttributes());
            if (! is_string($credentials['bound_to']) || ! hash_equals($expected, $credentials['bound_to'])) {
                throw new RuntimeException('Stored credentials are not bound to this remote target.');
            }
            unset($credentials['bound_to']);
        }

        return $credentials;
    }

    public static function fingerprint(string $siteId, array $attributes): string
    {
        $parts = [
            $siteId,
            (string) ($attributes['protocol'] ?? ''),
            (string) ($attributes['host'] ?? ''),
            (string) ($attributes['port'] ?? ''),
            (string) ($attributes['username'] ?? ''),
        ];
        if (($attributes['protocol'] ?? null) === 'wordpress') {
            $parts[] = (string) ($attributes['site_url'] ?? '');
            // Preserve the legacy application-password binding while binding bridge keys
            // to the distinct authentication mode as well.
            if (($attributes['auth_method'] ?? null) === 'devpilot_bridge_hmac') {
                $parts[] = 'devpilot_bridge_hmac';
            }
        }

        return hash('sha256', implode('|', $parts));
    }
}
