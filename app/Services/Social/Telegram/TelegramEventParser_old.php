<?php

namespace App\Services\Social\Telegram;

use App\Enums\Social\MessageType;
use App\Jobs\social\SocialMessageReceivedJob;
use App\Models\Social\SocialAccount;
use App\Models\Social\SocialConversation;
use App\Models\Social\SocialEvent;
use App\Models\Social\SocialMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class TelegramEventParser
{
    public function handle(SocialEvent $event): void
    {
        $payload = $event->payload;

        Log::info('[Telegram] RAW_EVENT', [
            'event_id'   => $event->id,
            'event_type' => $event->event_type,
        ]);

        $account = SocialAccount::find($event->social_account_id);

        if (!$account || !$account->is_active) {
            Log::warning('[Telegram] SocialAccount introuvable ou inactif', [
                'event_id'          => $event->id,
                'social_account_id' => $event->social_account_id,
            ]);
            return;
        }

        match ($event->event_type) {
            'message'        => $this->handleMessage($account, $payload['message']),
            'edited_message' => $this->handleEditedMessage($account, $payload['edited_message']),
            'channel_post'   => $this->handleChannelPost($account, $payload['channel_post']),
            'callback_query' => $this->handleCallbackQuery($account, $payload['callback_query']),
            default          => Log::info('[Telegram] Event type ignoré', [
                'event_type' => $event->event_type,
            ]),
        };
    }

    // ─────────────────────────────────────────────────────────
    // MESSAGE (DM ou message dans un groupe)
    // ─────────────────────────────────────────────────────────

    private function handleMessage(SocialAccount $account, array $msg): void
    {
        $messageId   = (string) ($msg['message_id'] ?? null);
        $text        = $msg['text'] ?? $msg['caption'] ?? null;
        $from        = $msg['from'] ?? null;
        $chat        = $msg['chat'] ?? null;
        $date        = $msg['date'] ?? null;
        $replyTo     = $msg['reply_to_message'] ?? null;

        if (!$messageId || !$chat) {
            Log::warning('[Telegram][Message] Event incomplet', $msg);
            return;
        }

        $chatId   = (string) $chat['id'];
        $chatType = $chat['type'] ?? 'private'; // private | group | supergroup | channel

        $publishedAt = $date
            ? Carbon::createFromTimestamp($date)
            : now();

        // ✅ Echo = message envoyé par le bot lui-même (réponse IA)
        $botId    = $account->metadata['bot_id'] ?? null;
        $senderId = (string) ($from['id'] ?? '');
        $isEcho   = $botId && $senderId === (string) $botId;

        if ($isEcho) {
            $this->handleEcho($account, $msg, $chatId, $messageId, $text, $replyTo, $publishedAt);
            return;
        }

        if (!$text) {
            $contentType = $this->resolveAttachmentType($msg);
            Log::info('[Telegram][Message] Message non-texte reçu', [
                'type'       => $contentType,
                'message_id' => $messageId,
                'chat_id'    => $chatId,
            ]);
            // Extensible pour gérer photos, fichiers, etc.
            return;
        }

        // ✅ Contexte : DM vs groupe
        $contextType = match ($chatType) {
            'private'                  => 'dm',
            'group', 'supergroup'      => 'group',
            'channel'                  => 'channel',
            default                    => 'dm',
        };

        $externalUserId = $contextType === 'dm'
            ? $senderId      // DM : conv par utilisateur
            : $chatId;       // Groupe/canal : conv par chat

        $conversation = $this->resolveConversation(
            $account,
            $externalUserId,
            $from,
            $chat,
            $contextType,
            $chatId,
        );

        // ✅ Résoudre le parent si c'est une réponse (reply_to_message)
        $parentMessage = $this->resolveParentMessage($replyTo, $publishedAt);

        $externalMessageId = $this->buildExternalMessageId($account->id, $chatId, $messageId);

        $message = SocialMessage::firstOrCreate(
            [
                'provider'            => 'telegram',
                'external_message_id' => $externalMessageId,
            ],
            [
                'social_conversation_id' => $conversation->id,
                'direction'              => 'incoming',
                'content'                => $text,
                'message_type'           => MessageType::TEXT->value,
                'generated_by_ai'        => false,
                'metadata' => [
                    'chat_id'           => $chatId,
                    'chat_type'         => $chatType,
                    'message_id'        => $messageId,
                    'sender_id'         => $senderId,
                    'sender_username'   => $from['username'] ?? null,
                    'parent_message_id' => $parentMessage?->id,
                    'is_reply'          => $replyTo !== null,
                    'raw'               => $msg,
                ],
                'published_at' => $publishedAt,
            ]
        );

        if ($message->wasRecentlyCreated) {
            Log::info('[Telegram][Message] Nouveau message entrant créé', [
                'message_id'      => $message->id,
                'conversation_id' => $conversation->id,
                'chat_type'       => $chatType,
                'is_reply'        => $replyTo !== null,
                'from'            => $from['username'] ?? $senderId,
            ]);

            SocialMessageReceivedJob::dispatch($message->id);
        }

        $this->touchConversation($conversation, $publishedAt);
    }

    // ─────────────────────────────────────────────────────────
    // ECHO (message envoyé par le bot = réponse IA)
    // ─────────────────────────────────────────────────────────

    private function handleEcho(
        SocialAccount $account,
        array         $msg,
        string        $chatId,
        string        $messageId,
        ?string       $text,
        ?array        $replyTo,
        Carbon        $publishedAt,
    ): void {

        $conversation = SocialConversation::where([
            'social_account_id' => $account->id,
            'provider'          => 'telegram',
            'context_id'        => $chatId,
        ])->latest('last_message_at')->first();

        if (!$conversation) {
            Log::warning('[Telegram][Echo] Conversation introuvable pour echo IA', [
                'chat_id'    => $chatId,
                'message_id' => $messageId,
            ]);
            return;
        }

        $parentMessage = $this->resolveParentMessage($replyTo, $publishedAt);

        $externalMessageId = $this->buildExternalMessageId($account->id, $chatId, $messageId);

        $message = SocialMessage::firstOrCreate(
            [
                'provider'            => 'telegram',
                'external_message_id' => $externalMessageId,
            ],
            [
                'social_conversation_id' => $conversation->id,
                'direction'              => 'outgoing',
                'content'                => $text ?? '[no content]',
                'message_type'           => MessageType::TEXT->value,
                'generated_by_ai'        => true,
                'metadata' => [
                    'chat_id'           => $chatId,
                    'message_id'        => $messageId,
                    'parent_message_id' => $parentMessage?->id,
                    'is_echo'           => true,
                    'raw'               => $msg,
                ],
                'published_at' => $publishedAt,
            ]
        );

        if ($message->wasRecentlyCreated) {
            Log::info('[Telegram][Echo] Message sortant IA enregistré', [
                'message_id'      => $message->id,
                'conversation_id' => $conversation->id,
            ]);
        }

        $this->touchConversation($conversation, $publishedAt);
    }

    // ─────────────────────────────────────────────────────────
    // EDITED MESSAGE
    // ─────────────────────────────────────────────────────────

    private function handleEditedMessage(SocialAccount $account, array $msg): void
    {
        $messageId = (string) ($msg['message_id'] ?? null);
        $chatId    = (string) ($msg['chat']['id'] ?? null);
        $newText   = $msg['text'] ?? null;

        if (!$messageId || !$chatId || !$newText) {
            return;
        }

        $externalMessageId = $this->buildExternalMessageId($account->id, $chatId, $messageId);

        $message = SocialMessage::where('provider', 'telegram')
            ->where('external_message_id', $externalMessageId)
            ->first();

        if (!$message) {
            Log::info('[Telegram][Edit] Message édité introuvable en base', [
                'external_message_id' => $externalMessageId,
            ]);
            return;
        }

        $message->update([
            'content'  => $newText,
            'metadata' => array_merge($message->metadata ?? [], [
                'edited_at'   => now()->toIso8601String(),
                'raw_edited'  => $msg,
            ]),
        ]);

        Log::info('[Telegram][Edit] Message mis à jour', ['message_id' => $message->id]);
    }

    // ─────────────────────────────────────────────────────────
    // CHANNEL POST (publication dans un canal)
    // ─────────────────────────────────────────────────────────

    private function handleChannelPost(SocialAccount $account, array $post): void
    {
        // ✅ Réutilise handleMessage — même structure,
        // sauf que 'from' est absent dans les posts de canal
        // (le bot est l'auteur implicite)
        $post['from'] = $post['from'] ?? [
            'id'         => $account->metadata['bot_id'] ?? 0,
            'is_bot'     => true,
            'first_name' => $account->metadata['bot_name'] ?? 'Bot',
        ];

        $this->handleMessage($account, $post);
    }

    // ─────────────────────────────────────────────────────────
    // CALLBACK QUERY (bouton inline cliqué)
    // ─────────────────────────────────────────────────────────

    private function handleCallbackQuery(SocialAccount $account, array $query): void
    {
        $queryId  = $query['id']   ?? null;
        $from     = $query['from'] ?? null;
        $data     = $query['data'] ?? null;
        $message  = $query['message'] ?? null;

        if (!$queryId || !$from) {
            return;
        }

        Log::info('[Telegram][CallbackQuery] Bouton inline cliqué', [
            'query_id'   => $queryId,
            'from'       => $from['username'] ?? $from['id'] ?? null,
            'data'       => $data,
            'account_id' => $account->id,
        ]);

        // ✅ Extensible : router selon $data pour déclencher des actions
        // Ex: data='approve_reply:message_id' → approuver une réponse IA
    }

    // ─────────────────────────────────────────────────────────
    // RESOLVE CONVERSATION
    // ─────────────────────────────────────────────────────────

    private function resolveConversation(
        SocialAccount $account,
        string        $externalUserId,
        ?array        $from,
        array         $chat,
        string        $contextType,
        string        $chatId,
    ): SocialConversation {

        $username    = $from['username'] ?? null;
        $displayName = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? '')) ?: null;
        $chatTitle   = $chat['title'] ?? null; // groupe/canal ont un titre

        return SocialConversation::firstOrCreate(
            [
                'social_account_id' => $account->id,
                'provider'          => 'telegram',
                'external_user_id'  => $externalUserId,
                'context_type'      => $contextType,
                'context_id'        => $chatId,
            ],
            [
                'site_id'               => $account->site_id,
                'external_username'     => $username,
                'external_display_name' => $displayName ?? $chatTitle ?? $username,
                'context_type'          => $contextType,
                'context_id'            => $chatId,
                'source_object_id'      => $chatId,
                'metadata' => [
                    'chat_id'    => $chatId,
                    'chat_type'  => $chat['type'] ?? null,
                    'chat_title' => $chatTitle,
                    'from'       => $from,
                ],
                'last_message_at' => now(),
            ]
        );
    }

    // ─────────────────────────────────────────────────────────
    // RESOLVE PARENT MESSAGE (reply_to_message)
    //
    // Telegram fournit l'objet complet du message parent
    // dans reply_to_message — pas d'aplatissement comme
    // Facebook/Instagram. On cherche simplement en base
    // via l'external_message_id du message cité.
    // ─────────────────────────────────────────────────────────

    private function resolveParentMessage(?array $replyTo, Carbon $currentPublishedAt): ?SocialMessage
    {
        if (!$replyTo) {
            return null;
        }

        $parentMessageId = (string) ($replyTo['message_id'] ?? null);
        $parentChatId    = (string) ($replyTo['chat']['id'] ?? null);

        if (!$parentMessageId || !$parentChatId) {
            return null;
        }

        // ✅ Avantage Telegram vs Facebook : parent_id pointe EXACTEMENT
        // vers le message cité (pas d'aplatissement). On cherche via
        // metadata->message_id + metadata->chat_id.
        return SocialMessage::where('provider', 'telegram')
            ->whereJsonContains('metadata->message_id', $parentMessageId)
            ->whereJsonContains('metadata->chat_id', $parentChatId)
            ->where('published_at', '<', $currentPublishedAt)
            ->orderByDesc('published_at')
            ->first();
    }

    // ─────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────

    /**
     * ID externe unique et déterministe par message.
     * Telegram garantit l'unicité de message_id par chat,
     * mais PAS entre différents chats → on préfixe avec chat_id.
     */
    private function buildExternalMessageId(string $accountId, string $chatId, string $messageId): string
    {
        return "tg:{$accountId}:{$chatId}:{$messageId}";
    }

    private function resolveAttachmentType(array $msg): string
    {
        return match (true) {
            isset($msg['photo'])    => 'photo',
            isset($msg['video'])    => 'video',
            isset($msg['audio'])    => 'audio',
            isset($msg['voice'])    => 'voice',
            isset($msg['document']) => 'document',
            isset($msg['sticker'])  => 'sticker',
            isset($msg['location']) => 'location',
            isset($msg['contact'])  => 'contact',
            default                 => 'unknown',
        };
    }

    private function touchConversation(SocialConversation $conversation, Carbon $publishedAt): void
    {
        $current = $conversation->last_message_at
            ? Carbon::parse($conversation->last_message_at)
            : null;

        if (!$current || $publishedAt->greaterThan($current)) {
            $conversation->update(['last_message_at' => $publishedAt]);
        }
    }
}
