<?php

namespace App\SocialChannels\YouTube;

use App\Models\Social\SocialAccount;
use App\Models\Social\SocialMessage;
use App\SocialChannels\Contracts\SocialChannelInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class YouTubeChannel implements SocialChannelInterface
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = 'https://www.googleapis.com/youtube/v3';
    }

    /**
     * La connexion YouTube se fait via le flow OAuth
     * (YouTubeConnectController::redirect/callback/storeChannel).
     * Cette méthode n'a rien à faire ici.
     */
    public function connect(): void
    {
        // No-op — voir YouTubeConnectController
    }

    /**
     * La déconnexion est gérée au niveau du SocialAccount
     * (is_active = false). Aucune action API spécifique
     * n'est requise côté Google pour l'instant.
     */
    public function disconnect(): void
    {
        // No-op — voir SocialAccount::is_active
    }

    /**
     * Remplacé par YouTubeChannelSyncService + YouTubeSyncCommentsCommand
     * (YouTube n'a pas de webhook — synchronisation via polling).
     */
    public function fetchMessages(): array
    {
        return [];
    }

    /**
     * Publication d'une réponse sur un commentaire YouTube.
     */
    public function sendReply(SocialAccount $account, SocialMessage $message): array
    {
        $metadata = $message->metadata ?? [];

        if (empty($message->content)) {
            throw new RuntimeException(
                "Contenu vide pour le message {$message->id}, impossible de répondre."
            );
        }

        // ✅ L'API YouTube (comments.insert) n'accepte que l'ID
        // du commentaire RACINE (top-level) comme parentId,
        // même si on répond à une réponse imbriquée.
        $topLevelCommentId = $metadata['top_level_comment_id']
            ?? $metadata['comment_id']
            ?? null;

        if (!$topLevelCommentId) {
            throw new RuntimeException(
                "top_level_comment_id (ou comment_id) manquant dans metadata du message {$message->id}."
            );
        }

        return $this->replyToComment(
            account: $account,
            parentCommentId: $topLevelCommentId,
            message: $message->content,
        );
    }

    /**
     * Réponse à un commentaire YouTube (toujours sur le commentaire racine).
     */
    private function replyToComment(
        SocialAccount $account,
        string $parentCommentId,
        string $message
    ): array {

        $payload = [
            'snippet' => [
                'parentId'     => $parentCommentId,
                'textOriginal' => $message,
            ],
        ];

        $response = $this->authorizedRequest($account)
            ->post("{$this->baseUrl}/comments?part=snippet", $payload);

        // ✅ Le token peut expirer entre la vérification proactive
        // et l'appel réel — on retente une fois après refresh forcé
        if ($response->status() === 401) {

            Log::warning('[YouTube] Token rejeté (401), refresh forcé', [
                'account_id' => $account->id,
            ]);

            $this->refreshToken($account);

            $response = $this->authorizedRequest($account)
                ->post("{$this->baseUrl}/comments?part=snippet", $payload);
        }

        if (!$response->successful()) {

            Log::error('[YouTube] Échec de la publication de la réponse', [
                'account_id'        => $account->id,
                'parent_comment_id' => $parentCommentId,
                'status'            => $response->status(),
                'body'              => $response->body(),
            ]);

            throw new RuntimeException($this->formatGoogleError($response));
        }

        return $response->json();
    }

    /**
     * Rafraîchit l'access_token Google via le refresh_token stocké.
     *
     * ⚠️ Modifie l'instance $account en mémoire ET persiste en DB.
     */
    public function refreshToken(SocialAccount $account): void
    {
        if (!$account->refresh_token) {
            throw new RuntimeException(
                "Aucun refresh_token disponible pour le SocialAccount {$account->id}. " .
                "L'utilisateur doit reconnecter sa chaîne YouTube."
            );
        }

        $response = Http::asForm()
            ->timeout(30)
            ->post('https://oauth2.googleapis.com/token', [
                'client_id'     => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'refresh_token' => $account->refresh_token,
                'grant_type'    => 'refresh_token',
            ]);

        if (!$response->successful()) {

            Log::error('[YouTube] Échec du rafraîchissement du token', [
                'account_id' => $account->id,
                'status'     => $response->status(),
                'body'       => $response->body(),
            ]);

            throw new RuntimeException(
                "Impossible de rafraîchir le token YouTube (compte {$account->id}): "
                . $this->formatGoogleError($response)
            );
        }

        $data = $response->json();

        $account->update([
            'access_token'     => $data['access_token'],
            'token_expires_at' => now()->addSeconds($data['expires_in'] ?? 3600),

            // Google ne renvoie PAS toujours un nouveau refresh_token —
            // on ne l'écrase que s'il en fournit un nouveau
            'refresh_token' => $data['refresh_token'] ?? $account->refresh_token,
        ]);

        Log::info('[YouTube] Access token rafraîchi avec succès', [
            'account_id' => $account->id,
            'expires_at' => $account->token_expires_at,
        ]);
    }

    /**
     * Retourne un access_token valide, en rafraîchissant
     * proactivement si nécessaire (expiré ou proche de l'expiration).
     */
    public function getValidAccessToken(SocialAccount $account): string
    {
        $expiresAt = $account->token_expires_at;

        $needsRefresh = !$account->access_token
            || !$expiresAt
            || now()->greaterThanOrEqualTo(
                \Illuminate\Support\Carbon::parse($expiresAt)->subSeconds(60)
            );

        if ($needsRefresh) {
            $this->refreshToken($account);
        }

        return $account->access_token;
    }

    /**
     * Client HTTP préconfiguré avec un access_token valide.
     *
     * ✅ Réutilisable par YouTubeChannelSyncService pour
     * récupérer les vidéos / commentaires (prochaine étape).
     */
    public function authorizedRequest(SocialAccount $account, int $timeout = 30): \Illuminate\Http\Client\PendingRequest
    {
        return Http::timeout($timeout)
            ->withToken($this->getValidAccessToken($account));
    }

    /**
     * Formate une erreur Google API en message lisible.
     */
    private function formatGoogleError(\Illuminate\Http\Client\Response $response): string
    {
        $body = $response->json();

        $message = $body['error']['message']
            ?? $body['error']['errors'][0]['reason']
            ?? $response->body();

        $code = $body['error']['code'] ?? $response->status();

        return "YouTube API error ({$code}): {$message}";
    }

    public function getProvider(): string
    {
        return 'youtube';
    }
}
