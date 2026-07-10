<?php

namespace App\Services\Social\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class EmbeddedSignupService
{
    private string $graphUrl;
    private string $appId;
    private string $appSecret;

    public function __construct()
    {
        $this->graphUrl  = 'https://graph.facebook.com/' . config('services.whatsapp.graph_version');
        $this->appId     = config('services.whatsapp.app_id');
        $this->appSecret = config('services.whatsapp.app_secret');
    }

    /**
     * Étape 1 — Échanger le code court (reçu du SDK JS) contre un User Access Token
     * puis le convertir en token longue durée (60 jours).
     */
    public function exchangeCode(string $code): array
    {
        // ✅ Étape 1a — Code → Short-lived User Access Token
        $response = Http::get("{$this->graphUrl}/oauth/access_token", [
            'client_id'     => $this->appId,
            'client_secret' => $this->appSecret,
            'code'          => $code,
        ]);

        if (!$response->successful()) {
            Log::error('[WhatsApp] exchangeCode failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new RuntimeException(
                "Échange de code échoué: " . ($response->json('error.message') ?? $response->body())
            );
        }

        $shortToken = $response->json('access_token');

        if (!$shortToken) {
            throw new RuntimeException('Aucun access_token reçu de Meta.');
        }

        // ✅ Étape 1b — Short-lived → Long-lived token (60 jours)
        $longResponse = Http::get("{$this->graphUrl}/oauth/access_token", [
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => $this->appId,
            'client_secret'     => $this->appSecret,
            'fb_exchange_token' => $shortToken,
        ]);

        if (!$longResponse->successful()) {
            Log::warning('[WhatsApp] Long-lived token exchange failed, using short-lived', [
                'status' => $longResponse->status(),
            ]);
            // Fallback : utiliser le short-lived token
            return [
                'access_token' => $shortToken,
                'expires_in'   => 3600,
                'is_long_lived'=> false,
            ];
        }

        return [
            'access_token' => $longResponse->json('access_token'),
            'expires_in'   => $longResponse->json('expires_in', 5184000), // 60 jours
            'is_long_lived'=> true,
        ];
    }

    /**
     * Étape 2 — Inspecter le token pour récupérer WABA ID + Phone Number ID
     */
    public function inspectToken(string $userAccessToken): array
    {
        $response = Http::get("{$this->graphUrl}/debug_token", [
            'input_token'  => $userAccessToken,
            'access_token' => $this->appId . '|' . $this->appSecret, // App token
        ]);

        if (!$response->successful()) {
            throw new RuntimeException(
                "debug_token échoué: " . ($response->json('error.message') ?? $response->body())
            );
        }

        $data = $response->json('data', []);

        Log::info('[WhatsApp] Token inspecté', [
            'app_id'             => $data['app_id']       ?? null,
            'user_id'            => $data['user_id']      ?? null,
            'scopes'             => $data['scopes']        ?? [],
            'granular_scopes'    => $data['granular_scopes'] ?? [],
        ]);

        return $data;
    }

    /**
     * Étape 3 — Récupérer les WABAs et numéros de téléphone liés à ce token
     */
    public function fetchWhatsAppBusinessAccounts(string $userAccessToken): array
    {
        // ✅ Récupérer les WABA accessibles
        $response = Http::withToken($userAccessToken)
            ->get("{$this->graphUrl}/me/businesses");

        if (!$response->successful()) {
            throw new RuntimeException(
                "Impossible de récupérer les business accounts: " . $response->body()
            );
        }

        $businesses = $response->json('data', []);
        $results    = [];

        foreach ($businesses as $business) {
            $businessId = $business['id'];

            // ✅ Pour chaque business, récupérer les WABAs
            $wabaResponse = Http::withToken($userAccessToken)
                ->get("{$this->graphUrl}/{$businessId}/owned_whatsapp_business_accounts", [
                    'fields' => 'id,name,currency,timezone_id,message_template_namespace',
                ]);

            if (!$wabaResponse->successful()) continue;

            foreach ($wabaResponse->json('data', []) as $waba) {
                $wabaId = $waba['id'];

                // ✅ Pour chaque WABA, récupérer les numéros
                $phonesResponse = Http::withToken($userAccessToken)
                    ->get("{$this->graphUrl}/{$wabaId}/phone_numbers", [
                        'fields' => 'id,display_phone_number,verified_name,quality_rating,status',
                    ]);

                $phones = $phonesResponse->successful()
                    ? $phonesResponse->json('data', [])
                    : [];

                $results[] = [
                    'waba_id'      => $wabaId,
                    'waba_name'    => $waba['name'] ?? null,
                    'business_id'  => $businessId,
                    'phone_numbers'=> $phones,
                ];
            }
        }

        return $results;
    }
}
