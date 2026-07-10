<?php

namespace App\Services\Social\Facebook;

use Illuminate\Support\Facades\Log;

class FacebookWebhookSecurityService
{
    public function isValid(
        string $payload,
        ?string $signature,
        string $provider = 'facebook'
    ): bool {

        /*if (!$signature) {
            return false;
        }*/

        // ✅ Chaque provider peut avoir son propre app_secret
        $secret = match ($provider) {
            'instagram' => config('services.instagram.client_secret')
                ?? config('services.facebook.app_secret'), // fallback si même app
            default     => config('services.facebook.app_secret'),
        };

        $expected = 'sha256=' . hash_hmac(
                'sha256',
                $payload,
                $secret
            );

        $facebookExpected = 'sha256=' . hash_hmac(
                'sha256',
                $payload,
                config('services.facebook.app_secret')
            );

        $instagramExpected = 'sha256=' . hash_hmac(
                'sha256',
                $payload,
                config('services.instagram.client_secret')
            );

        Log::debug('Meta compare', [
            'received' => $signature,
            'facebook_match' => hash_equals($facebookExpected, $signature ?? ''),
            'instagram_match' => hash_equals($instagramExpected, $signature ?? ''),
        ]);

        Log::debug('[Meta][Security] Vérification signature', [
            'provider'          => $provider,
            'payload_length'    => strlen($payload),
            'payload'           => $payload,
            'received'          => $signature,
            'expected'          => $expected,
            'secret_first4'     => substr($secret, 0, 4) . '...',
            'match'             => hash_equals($expected, $signature ?? ''),
        ]);

        return hash_equals(
            $expected,
            $signature
        );
    }
}
