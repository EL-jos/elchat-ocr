<?php

namespace App\Services\Social\Telegram;

use App\Enums\Social\MessageType;
use App\Jobs\social\SocialMessageReceivedJob;
use App\Models\Social\SocialAccount;
use App\Models\Social\SocialConversation;
use App\Models\Social\SocialEvent;
use App\Models\Social\SocialMessage;
use App\Services\Social\ConversationBridgeService;
use App\Services\Social\UserResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class TelegramEventParser
{
    public function __construct(
        private readonly UserResolver              $userResolver,
        private readonly ConversationBridgeService $conversationBridge,
    ) {}

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
        $messageId = (string) ($msg['message_id'] ?? null);
        $text      = $msg['text'] ?? $msg['caption'] ?? null;
        $from      = $msg['from'] ?? null;
        $chat      = $msg['chat'] ?? null;
        $date      = $msg['date'] ?? null;
        $replyTo   = $msg['reply_to_message'] ?? null;

        if (!$messageId || !$chat) {
            Log::warning('[Telegram][Message] Event incomplet', $msg);
            return;
        }

        $chatId   = (string) $chat['id'];
        $chatType = $chat['type'] ?? 'private';

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
            // ✅ Cas particulier : message de type "contact" → on extrait
            // le numéro de téléphone pour enrichir le User si besoin.
            if (isset($msg['contact'])) {
                $this->handleContactMessage($account, $msg, $chat, $from, $chatId, $chatType, $publishedAt);
            } else {
                Log::info('[Telegram][Message] Message non-texte reçu', [
                    'type'       => $this->resolveAttachmentType($msg),
                    'message_id' => $messageId,
                    'chat_id'    => $chatId,
                ]);
            }
            return;
        }

        $contextType = match ($chatType) {
            'private'             => 'dm',
            'group', 'supergroup' => 'group',
            'channel'             => 'channel',
            default               => 'dm',
        };

        $externalUserId = $contextType === 'dm' ? $senderId : $chatId;

        $conversation = $this->resolveConversation(
            $account,
            $externalUserId,
            $from,
            $chat,
            $contextType,
            $chatId,
        );

        $parentMessage     = $this->resolveParentMessage($replyTo, $publishedAt);
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
                'metadata'               => [
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

            // ── Bridge User + Conversation ELChat ─────────────────────────
            // Telegram expose directement phone_number et username dans
            // l'objet `from` (si l'utilisateur l'a autorisé).
            $isNewConversation = $conversation->wasRecentlyCreated;

            $this->bridgeUser(
                account:           $account,
                from:              $from,
                conversation:      $conversation,
                socialMessage:     $message,
                isNewConversation: $isNewConversation,
                contextBlock:      $this->buildContextBlock($chat, $chatType),
            );

            SocialMessageReceivedJob::dispatch($message->id);
        }

        $this->touchConversation($conversation, $publishedAt);
    }

    // ─────────────────────────────────────────────────────────
    // CONTACT MESSAGE (partage de coordonnées via bouton Telegram)
    //
    // Telegram permet à un utilisateur de partager son numéro
    // via un message de type "contact". On l'exploite pour enrichir
    // le User ELChat avec le téléphone réel.
    // ─────────────────────────────────────────────────────────

    private function handleContactMessage(
        SocialAccount $account,
        array         $msg,
        array         $chat,
        ?array        $from,
        string        $chatId,
        string        $chatType,
        Carbon        $publishedAt,
    ): void {
        $contact  = $msg['contact'];
        $phone    = $contact['phone_number'] ?? null;
        $senderId = (string) ($from['id'] ?? ($contact['user_id'] ?? ''));

        if (!$senderId) {
            return;
        }

        Log::info('[Telegram][Contact] Numéro de téléphone reçu', [
            'sender_id' => $senderId,
            'phone'     => $phone ? '***' : null, // masqué dans les logs
        ]);

        // On résout le User pour enrichir son téléphone
        $this->userResolver->resolve(
            account:        $account,
            externalUserId: $senderId,
            displayName:    trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? '')) ?: null,
            username:       $from['username'] ?? null,
            email:          null,
            phone:          $phone,
        );
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

        $parentMessage     = $this->resolveParentMessage($replyTo, $publishedAt);
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
                'metadata'               => [
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
                'edited_at'  => now()->toIso8601String(),
                'raw_edited' => $msg,
            ]),
        ]);

        Log::info('[Telegram][Edit] Message mis à jour', ['message_id' => $message->id]);
    }

    // ─────────────────────────────────────────────────────────
    // CHANNEL POST (publication dans un canal)
    // ─────────────────────────────────────────────────────────

    private function handleChannelPost(SocialAccount $account, array $post): void
    {
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
        $queryId = $query['id']   ?? null;
        $from    = $query['from'] ?? null;
        $data    = $query['data'] ?? null;

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
    }

    // ─────────────────────────────────────────────────────────
    // BRIDGE USER + CONVERSATION ELCHAT
    // ─────────────────────────────────────────────────────────

    private function bridgeUser(
        SocialAccount     $account,
        ?array            $from,
        SocialConversation $conversation,
        SocialMessage     $socialMessage,
        bool              $isNewConversation,
        string            $contextBlock,
    ): void {
        $senderId = (string) ($from['id'] ?? '');

        if (!$senderId) {
            return;
        }

        $displayName = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? '')) ?: null;
        $username    = $from['username'] ?? null;

        // ✅ Telegram peut exposer le téléphone dans l'objet `from`
        // si l'utilisateur l'a partagé via bot (rare mais possible)
        $phone = $from['phone_number'] ?? null;

        // Telegram n'expose jamais l'email via l'API Bot
        $email = null;

        $user = $this->userResolver->resolve(
            account:        $account,
            externalUserId: $senderId,
            displayName:    $displayName,
            username:       $username,
            email:          $email,
            phone:          $phone,
        );

        if ($isNewConversation) {
            $this->conversationBridge->bridge(
                socialConv:        $conversation,
                socialMessage:     $socialMessage,
                user:              $user,
                isNewConversation: true,
                videoContext:      [
                    // Sur Telegram, pas de vidéo — on passe le contexte
                    // du chat (titre du groupe/canal) pour que le LLM
                    // puisse reformuler avec un minimum de contexte.
                    'title'       => $contextBlock ?: null,
                    'description' => null,
                ],
            );
        }
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
        $chatTitle   = $chat['title'] ?? null;

        return SocialConversation::firstOrCreate(
            [
                'social_account_id' => $account->id,
                'provider'          => 'telegram',
                'external_user_id'  => $externalUserId,
                'context_type'      => $contextType,
                'context_id'        => $chatId,
                'context_id_hash'       => hash('sha256', $chatId), // ← ajout
            ],
            [
                'site_id'               => $account->site_id,
                'external_username'     => $username,
                'external_display_name' => $displayName ?? $chatTitle ?? $username,
                'context_type'          => $contextType,
                'context_id'            => $chatId,
                'context_id_hash'       => hash('sha256', $chatId), // ← ajout
                'source_object_id'      => $chatId,
                'metadata'              => [
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
     * Construit le bloc de contexte passé au LLM pour la reformulation.
     * Sur Telegram, le contexte disponible est le titre du groupe/canal.
     * Sur un DM, il n'y a pas de contexte supplémentaire.
     */
    private function buildContextBlock(array $chat, string $chatType): string
    {
        if ($chatType === 'private') {
            return '';
        }

        $chatTitle = $chat['title'] ?? null;

        if (!$chatTitle) {
            return '';
        }

        return "Nom du groupe/canal : {$chatTitle}";
    }

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
