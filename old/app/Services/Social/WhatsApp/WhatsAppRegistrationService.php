<?php

namespace App\Services\Social\WhatsApp;

use App\Models\Site;
use App\Models\Social\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class WhatsAppRegistrationService
{
    private string $graphUrl;

    public function __construct()
    {
        $this->graphUrl = 'https://graph.facebook.com/' . config('services.whatsapp.graph_version');
    }

    /**
     * Enregistre un numéro WhatsApp Business pour un site ELChat.
     * Crée ou met à jour le SocialAccount correspondant.
     */
    public function register(
        Site   $site,
        string $wabaId,
        string $phoneNumberId,
        string $userAccessToken,
        array  $phoneData   = [],
        array  $wabaData    = [],
    ): SocialAccount {

        // ✅ Abonner l'app au WABA pour recevoir les webhooks
        $this->subscribeToWaba($wabaId, $userAccessToken);

        // ✅ Récupérer les infos du numéro si non fournies
        if (empty($phoneData)) {
            $phoneData = $this->fetchPhoneNumber($phoneNumberId, $userAccessToken);
        }

        $displayPhone  = $phoneData['display_phone_number'] ?? null;
        $verifiedName  = $phoneData['verified_name']        ?? null;
        $status        = $phoneData['status']               ?? null;

        $socialAccount = SocialAccount::updateOrCreate(
            [
                'site_id'             => $site->id,
                'provider'            => 'whatsapp',
                'provider_account_id' => $phoneNumberId,
            ],
            [
                'account_name'     => $verifiedName ?? $displayPhone ?? 'WhatsApp Business',
                'access_token'     => $userAccessToken,
                'refresh_token'    => null,
                'token_expires_at' => now()->addDays(60),
                'metadata' => [
                    'waba_id'             => $wabaId,
                    'phone_number_id'     => $phoneNumberId,
                    'display_phone'       => $displayPhone,
                    'verified_name'       => $verifiedName,
                    'phone_status'        => $status,
                    'waba_name'           => $wabaData['waba_name']    ?? null,
                    'business_id'         => $wabaData['business_id']  ?? null,
                    'webhook_subscribed'   => true,
                    'webhook_subscribed_at'=> now()->toIso8601String(),
                ],
                'is_active' => true,
            ]
        );

        Log::info('[WhatsApp] Compte enregistré', [
            'account_id'      => $socialAccount->id,
            'phone_number_id' => $phoneNumberId,
            'waba_id'         => $wabaId,
            'display_phone'   => $displayPhone,
        ]);

        return $socialAccount;
    }

    /**
     * Abonne l'app Meta aux événements du WABA (messages, statuts, etc.)
     */
    private function subscribeToWaba(string $wabaId, string $userAccessToken): void
    {
        $response = Http::withToken($userAccessToken)
            ->post("{$this->graphUrl}/{$wabaId}/subscribed_apps");

        if (!$response->successful()) {
            Log::warning('[WhatsApp] subscribed_apps échoué', [
                'waba_id' => $wabaId,
                'status'  => $response->status(),
                'body'    => $response->body(),
            ]);
            // Non-fatal : l'abonnement peut déjà exister
        } else {
            Log::info('[WhatsApp] WABA abonné aux webhooks', ['waba_id' => $wabaId]);
        }
    }

    /**
     * Récupère les infos d'un numéro de téléphone WhatsApp Business
     */
    public function fetchPhoneNumber(string $phoneNumberId, string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->get("{$this->graphUrl}/{$phoneNumberId}", [
                'fields' => 'id,display_phone_number,verified_name,quality_rating,status,platform_type',
            ]);

        if (!$response->successful()) {
            Log::warning('[WhatsApp] fetchPhoneNumber échoué', [
                'phone_number_id' => $phoneNumberId,
                'status'          => $response->status(),
            ]);
            return [];
        }

        return $response->json();
    }

    /**
     * Désabonner l'app du WABA lors de la déconnexion
     */
    public function unsubscribeFromWaba(string $wabaId, string $accessToken): void
    {
        try {
            Http::withToken($accessToken)
                ->delete("{$this->graphUrl}/{$wabaId}/subscribed_apps");

            Log::info('[WhatsApp] WABA désabonné', ['waba_id' => $wabaId]);
        } catch (Throwable $e) {
            Log::warning('[WhatsApp] unsubscribeFromWaba échoué', ['error' => $e->getMessage()]);
        }
    }
}
