<?php

namespace App\SocialChannels\Telegram;

use App\Models\Social\SocialAccount;
use App\Models\Social\SocialMessage;
use App\SocialChannels\Contracts\SocialChannelInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TelegramChannel implements SocialChannelInterface
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('services.telegram.bot_api', 'https://api.telegram.org');
    }

    public function connect(): void
    {
        // No-op — voir TelegramConnectController::connect()
    }

    public function disconnect(): void
    {
        // No-op — voir TelegramConnectController::disconnect()
    }

    public function fetchMessages(): array
    {
        // No-op — Telegram pousse via webhook (pas de polling)
        return [];
    }

    /**
     * Envoie une réponse Telegram selon le contexte du message.
     *
     * Trois cas possibles selon le context_type de la conversation :
     *   - dm / group / supergroup → sendMessage() avec reply_to_message_id
     *   - channel                 → sendMessage() sans reply (pas de reply dans les canaux)
     */
    public function sendReply(SocialAccount $account, SocialMessage $message): array
    {
        $metadata = $message->metadata ?? [];

        $chatId    = $metadata['chat_id']    ?? null;
        $messageId = $metadata['message_id'] ?? null;
        $chatType  = $metadata['chat_type']  ?? 'private';

        if (!$chatId) {
            throw new RuntimeException(
                "chat_id manquant dans metadata du message {$message->id}."
            );
        }

        if (empty($message->content)) {
            throw new RuntimeException(
                "Contenu vide pour le message {$message->id}, impossible d'envoyer."
            );
        }

        // ✅ Dans un canal, on ne peut pas "répondre" à un message
        // spécifique — on envoie simplement dans le canal
        $replyToMessageId = ($chatType !== 'channel' && $messageId)
            ? (int) $messageId
            : null;

        return $this->sendMessage(
            account: $account,
            chatId: $chatId,
            text: $message->content,
            replyToMessageId: $replyToMessageId,
        );
    }

    /**
     * Envoie un message Telegram via sendMessage().
     * Méthode publique — réutilisable pour des envois proactifs.
     */
    public function sendMessage(
        SocialAccount $account,
        string        $chatId,
        string        $text,
        ?int          $replyToMessageId = null,
        array         $extra            = [],
    ): array {

        $botToken = $account->access_token;

        $payload = array_merge([
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML', // ✅ permet <b>, <i>, <a href> dans les réponses IA
        ], $extra);

        // ✅ reply_to_message_id uniquement si fourni (DM / groupe)
        if ($replyToMessageId !== null) {
            $payload['reply_parameters'] = [
                'message_id' => $replyToMessageId,
            ];
        }

        $response = Http::timeout(30)
            ->post("{$this->baseUrl}/bot{$botToken}/sendMessage", $payload);

        // ✅ Le token peut être révoqué par l'utilisateur via @BotFather
        if ($response->status() === 401) {
            Log::error('[Telegram] Token révoqué ou invalide (401)', [
                'account_id' => $account->id,
            ]);
            throw new RuntimeException(
                "Token Telegram révoqué pour le compte {$account->id}. " .
                "L'utilisateur doit reconnecter son bot."
            );
        }

        // ✅ Chat non trouvé ou bot banni du groupe
        if ($response->status() === 400) {
            $error = $response->json('description', $response->body());

            Log::error('[Telegram] Envoi impossible (400)', [
                'account_id' => $account->id,
                'chat_id'    => $chatId,
                'error'      => $error,
            ]);

            throw new RuntimeException(
                "Telegram sendMessage erreur (400): {$error}"
            );
        }

        if (!$response->successful() || !$response->json('ok')) {
            $error = $response->json('description', $response->body());

            Log::error('[Telegram] Échec sendMessage', [
                'account_id' => $account->id,
                'chat_id'    => $chatId,
                'status'     => $response->status(),
                'error'      => $error,
            ]);

            throw new RuntimeException(
                "Telegram sendMessage échoué ({$response->status()}): {$error}"
            );
        }

        Log::info('[Telegram] Message envoyé avec succès', [
            'account_id'          => $account->id,
            'chat_id'             => $chatId,
            'reply_to_message_id' => $replyToMessageId,
        ]);

        return $response->json('result', []);
    }

    /**
     * Telegram ne nécessite pas de refresh token.
     * Le bot token est permanent jusqu'à révocation via @BotFather.
     */
    public function refreshToken(SocialAccount $account): void
    {
        // No-op — bot tokens Telegram n'expirent pas
    }

    public function getProvider(): string
    {
        return 'telegram';
    }
}
