<?php

namespace App\SocialChannels\Slack;

use App\Models\Social\SocialAccount;
use App\Models\Social\SocialMessage;
use App\SocialChannels\Contracts\SocialChannelInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SlackChannel implements SocialChannelInterface
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = 'https://slack.com/api';
    }

    public function connect(): void
    {
        // No-op — voir SlackConnectController
    }

    public function disconnect(): void
    {
        // No-op — voir SocialAccount::is_active
    }

    public function fetchMessages(): array
    {
        return [];
    }

    /**
     * Publication d'une réponse à un message Slack.
     *
     * Répond toujours EN THREAD (sous le thread_ts du message
     * racine), comme demandé : équivalent du parent_message_id
     * utilisé pour Facebook/YouTube/Instagram.
     */
    public function sendReply(SocialAccount $account, SocialMessage $message): array
    {
        $metadata = $message->metadata ?? [];

        if (empty($message->content)) {
            throw new RuntimeException(
                "Contenu vide pour le message {$message->id}, impossible de répondre."
            );
        }

        $channelId = $metadata['channel_id'] ?? null;

        // ✅ Le thread_ts à utiliser est TOUJOURS celui du message racine
        // du thread (équivalent top_level_comment_id pour YouTube).
        // Si le message courant n'est pas dans un thread, on démarre
        // un thread en répondant à son propre 'ts'.
        $threadTs = $metadata['thread_ts'] ?? $metadata['ts'] ?? null;

        if (!$channelId || !$threadTs) {
            throw new RuntimeException(
                "channel_id ou thread_ts manquant dans metadata du message {$message->id}."
            );
        }

        return $this->postMessage(
            account: $account,
            channelId: $channelId,
            text: $message->content,
            threadTs: $threadTs,
        );
    }

    /**
     * Poste un message dans un channel Slack (en thread).
     */
    private function postMessage(
        SocialAccount $account,
        string $channelId,
        string $text,
        string $threadTs,
    ): array {

        $response = Http::timeout(30)
            ->withToken($account->access_token)
            ->asForm()
            ->post("{$this->baseUrl}/chat.postMessage", [
                'channel'   => $channelId,
                'text'      => $text,
                'thread_ts' => $threadTs,
            ]);

        $body = $response->json();

        // ✅ Slack retourne TOUJOURS du 200, même en cas d'erreur —
        // le vrai statut est dans body.ok / body.error
        if (!$response->successful() || !($body['ok'] ?? false)) {

            Log::error('[Slack] Échec de l\'envoi du message', [
                'account_id' => $account->id,
                'channel_id' => $channelId,
                'thread_ts'  => $threadTs,
                'error'      => $body['error'] ?? 'unknown',
            ]);

            // ✅ Token invalide/révoqué → message clair pour le job appelant
            if (in_array($body['error'] ?? null, ['invalid_auth', 'token_revoked', 'account_inactive'])) {
                throw new RuntimeException(
                    "Token Slack invalide pour le compte {$account->id}. Reconnexion requise."
                );
            }

            // ✅ Bot pas invité dans le channel — erreur fréquente et actionnable
            if (($body['error'] ?? null) === 'not_in_channel') {
                throw new RuntimeException(
                    "Le Bot n'est pas invité dans le channel {$channelId}. " .
                    "Invitez-le via /invite @ELChat dans Slack."
                );
            }

            throw new RuntimeException(
                "Slack API error: " . ($body['error'] ?? $response->body())
            );
        }

        return $body;
    }

    /**
     * Slack Bot tokens (xoxb-...) n'expirent pas par défaut,
     * sauf si "Token Rotation" est activée sur l'app Slack.
     * Cette méthode existe pour respecter l'interface, mais
     * est un no-op tant que la rotation n'est pas activée.
     */
    public function refreshToken(SocialAccount $account): void
    {
        if (!$account->refresh_token) {
            // Rotation non activée — rien à faire, le token est stable
            return;
        }

        $response = Http::asForm()->post('https://slack.com/api/oauth.v2.access', [
            'client_id'     => config('services.slack.client_id'),
            'client_secret' => config('services.slack.client_secret'),
            'grant_type'    => 'refresh_token',
            'refresh_token' => $account->refresh_token,
        ]);

        $body = $response->json();

        if (!$response->successful() || !($body['ok'] ?? false)) {
            Log::error('[Slack] Échec du rafraîchissement du token', [
                'account_id' => $account->id,
                'error'      => $body['error'] ?? null,
            ]);

            throw new RuntimeException(
                "Impossible de rafraîchir le token Slack (compte {$account->id}): "
                . ($body['error'] ?? 'unknown error')
            );
        }

        $account->update([
            'access_token'     => $body['access_token'],
            'refresh_token'    => $body['refresh_token'] ?? $account->refresh_token,
            'token_expires_at' => isset($body['expires_in'])
                ? now()->addSeconds($body['expires_in'])
                : null,
        ]);

        Log::info('[Slack] Access token rafraîchi avec succès', [
            'account_id' => $account->id,
        ]);
    }

    public function getProvider(): string
    {
        return 'slack';
    }
}
