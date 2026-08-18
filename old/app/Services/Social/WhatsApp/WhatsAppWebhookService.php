<?php

namespace App\Services\Social\WhatsApp;

use Illuminate\Support\Facades\Log;

class WhatsAppWebhookService
{
    /**
     * Vérifie la signature HMAC-SHA256 du payload webhook Meta WhatsApp.
     * Identique à Facebook — même mécanisme de signature.
     */
    public function isValidSignature(string $payload, ?string $signature): bool
    {
        if (!$signature) {
            Log::warning('[WhatsApp][Webhook] Signature manquante');
            return false;
        }

        $expected = 'sha256=' . hash_hmac(
                'sha256',
                $payload,
                config('services.whatsapp.app_secret')
            );

        $valid = hash_equals($expected, $signature);

        if (!$valid) {
            Log::warning('[WhatsApp][Webhook] Signature invalide', [
                'expected_prefix' => substr($expected, 0, 20) . '...',
                'received_prefix' => substr($signature, 0, 20) . '...',
            ]);
        }

        return $valid;
    }

    /**
     * Détermine le type d'événement WhatsApp à partir du payload
     */
    public function resolveEventType(array $value): string
    {
        return match (true) {
            !empty($value['messages'])              => 'message',
            !empty($value['statuses'])              => 'status',
            !empty($value['contacts'])              => 'contact',
            !empty($value['errors'])                => 'error',
            default                                 => 'unknown',
        };
    }
}
