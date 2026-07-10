<?php

namespace App\Services\Social\Slack;

use Illuminate\Support\Facades\Log;

class SlackWebhookSecurityService
{
    /**
     * Vérifie la signature Slack (Signing Secret).
     *
     * Format Slack (différent de Meta) :
     * base_string = "v0:{timestamp}:{raw_body}"
     * signature   = "v0=" . hash_hmac('sha256', base_string, signing_secret)
     */
    public function isValid(string $payload, ?string $signature, ?string $timestamp): bool
    {
        if (!$signature || !$timestamp) {
            return false;
        }

        // ✅ Protection replay attack : rejeter si timestamp > 5 min d'écart
        if (abs(time() - (int) $timestamp) > 300) {
            Log::warning('[Slack][Security] Timestamp hors fenêtre (replay potentiel)', [
                'timestamp' => $timestamp,
                'now'       => time(),
            ]);
            return false;
        }

        $baseString = "v0:{$timestamp}:{$payload}";

        $expected = 'v0=' . hash_hmac(
                'sha256',
                $baseString,
                config('services.slack.signing_secret')
            );

        return hash_equals($expected, $signature);
    }
}
