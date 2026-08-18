<?php

namespace App\Services\Social\Facebook;

use App\Enums\Social\MessageType;
use App\Jobs\social\SocialMessageReceivedJob;
use App\Models\Social\SocialAccount;
use App\Models\Social\SocialConversation;
use App\Models\Social\SocialEvent;
use App\Models\Social\SocialMessage;
use App\Services\Social\ConversationBridgeService;
use App\Services\Social\SocialImageAnalysisService;
use App\Services\Social\UserResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class FacebookEventParser
{
    // Providers supportés par ce parser
    private const SUPPORTED_OBJECTS = ['page', 'instagram'];

    // Champs feed par provider
    private const FEED_FIELD      = 'feed';
    private const COMMENT_FIELD   = 'comments';

    public function __construct(
        private readonly UserResolver              $userResolver,
        private readonly ConversationBridgeService $conversationBridge,
        private readonly SocialImageAnalysisService $socialImageAnalysisService, // 🆕
    ) {}

    // ─────────────────────────────────────────────────────────
    // ENTRY POINT
    // ─────────────────────────────────────────────────────────

    public function handle(SocialEvent $event): void
    {
        $payload = $event->payload;

        Log::info('[Meta] RAW_PAYLOAD', [
            'event_id' => $event->id,
            'object'   => $payload['object'] ?? 'unknown',
        ]);

        // ✅ Payload de test Meta (ping de validation webhook)
        if (isset($payload['sample'])) {
            Log::info('[Meta] Payload de test ignoré', [
                'field' => $payload['sample']['field'] ?? 'unknown',
            ]);
            return;
        }

        $object = $payload['object'] ?? null;

        if (!isset($payload['entry']) || !in_array($object, self::SUPPORTED_OBJECTS, true)) {
            Log::warning('[Meta] Payload non reconnu ou object non supporté', [
                'object' => $object,
            ]);
            return;
        }

        $isInstagram = $object === 'instagram';

        foreach ($payload['entry'] as $entry) {
            $this->processEntry($entry, $isInstagram);
        }
    }

    // ─────────────────────────────────────────────────────────
    // PROCESS ENTRY
    // ─────────────────────────────────────────────────────────

    private function processEntry(array $entry, bool $isInstagram): void
    {
        $entryId = $entry['id'] ?? null;

        if (!$entryId) {
            Log::warning('[Meta] Entry sans ID ignorée');
            return;
        }

        $account = $isInstagram
            ? $this->resolveAccount($entryId, 'instagram')
            : $this->resolveAccount($entryId, 'facebook');

        if (!$account) {
            return;
        }

        // ── Flux 1 : Messages inbox (DM Messenger / Instagram DM) ─────────
        if (!empty($entry['messaging'])) {
            foreach ($entry['messaging'] as $messagingEvent) {
                $this->handleMessage($account, $messagingEvent, $isInstagram);
            }
            return;
        }

        // ── Flux 2 : Changes (feed Facebook / commentaires Instagram) ──────
        if (!empty($entry['changes'])) {
            foreach ($entry['changes'] as $change) {
                $field = $change['field'] ?? null;
                $value = $change['value'] ?? [];

                if (!$isInstagram && $field === self::FEED_FIELD) {
                    $this->handleFeed($account, $value);
                    continue;
                }

                if ($isInstagram && $field === self::COMMENT_FIELD) {
                    $this->handleInstagramComment($account, $value);
                    continue;
                }

                Log::info('[Meta] Change field ignoré', [
                    'field'       => $field,
                    'is_instagram'=> $isInstagram,
                ]);
            }
            return;
        }

        Log::info('[Meta] Entry sans changes ni messaging ignorée', [
            'entry_id'    => $entryId,
            'is_instagram'=> $isInstagram,
        ]);
    }

    // ─────────────────────────────────────────────────────────
    // RESOLVE ACCOUNT
    // ─────────────────────────────────────────────────────────

    private function resolveAccount(string $providerId, string $provider): ?SocialAccount
    {
        $account = SocialAccount::where('provider', $provider)
            ->where('provider_account_id', $providerId)
            ->where('is_active', true)
            ->first();

        if (!$account) {
            Log::warning('[Meta] SocialAccount introuvable ou inactif', [
                'provider'    => $provider,
                'provider_id' => $providerId,
            ]);
        }

        return $account;
    }

    // ═════════════════════════════════════════════════════════
    // FACEBOOK FEED (commentaires sur posts)
    // ═════════════════════════════════════════════════════════

    private function handleFeed(SocialAccount $account, array $value): void
    {
        $item = $value['item'] ?? null;
        $verb = $value['verb'] ?? null;

        if (!in_array($item, ['comment', 'status', 'post'], true)) {
            Log::info('[Facebook][Feed] Item ignoré', ['item' => $item]);
            return;
        }

        if ($verb !== 'add') {
            Log::info('[Facebook][Feed] Verb ignoré', ['verb' => $verb]);
            return;
        }

        $from      = $value['from']       ?? null;
        $postId    = $value['post_id']    ?? null;
        $commentId = $value['comment_id'] ?? null;
        $parentId  = $value['parent_id']  ?? null;
        $content   = $value['message']    ?? $value['story'] ?? null;
        $photoUrl = $value['photo'] ?? null; // 🆕 Meta expose value.photo si le commentaire a une photo

        if (!$from || !isset($from['id'])) {
            Log::warning('[Facebook][Feed] "from" manquant', $value);
            return;
        }

        if (!$postId) {
            Log::warning('[Facebook][Feed] "post_id" manquant', $value);
            return;
        }

        // ✅ Réponse = parent_id existe ET diffère du post_id
        $isReply = $parentId && $parentId !== $postId;

        // ✅ Echo = la page répond via Graph API
        if ($from['id'] === $account->provider_account_id) {
            $this->handleFeedEcho(
                account:   $account,
                value:     $value,
                postId:    $postId,
                commentId: $commentId,
                parentId:  $parentId,
                isReply:   $isReply,
                content:   $content,
            );
            return;
        }

        $publishedAt = isset($value['created_time'])
            ? Carbon::createFromTimestamp($value['created_time'])
            : now();

        $conversation = $this->resolveFeedConversation($account, $from, $postId);

        $parentMessage = $this->resolveParentMessage(
            parentId:           $parentId,
            contextId:          $postId,
            currentCreatedTime: $value['created_time'] ?? null,
            isReply:            $isReply,
            provider:           'facebook',
            contextMetaKey:     'post_id',
        );

        $externalMessageId = $commentId
            ?? ('fb_feed_' . ($value['created_time'] ?? uniqid()));

        $messageType = MessageType::TEXT->value;
        $imageMeta   = null;

        if ($photoUrl) {
            $visionResult = $this->socialImageAnalysisService->analyzeFromUrl(
                $photoUrl,
                $content,
                "facebook-feed-comment:{$commentId}",
            );

            if ($visionResult) {
                $content     = $this->socialImageAnalysisService->buildEnrichedContent($content, $visionResult);
                $messageType = MessageType::IMAGE->value;
                $imageMeta   = $this->socialImageAnalysisService->buildMetadataBlock($visionResult, $photoUrl);
            }
        }

        $message = SocialMessage::firstOrCreate(
            [
                'provider'            => 'facebook',
                'external_message_id' => $externalMessageId,
            ],
            [
                'social_conversation_id' => $conversation->id,
                'direction'              => 'incoming',
                'content'                => $content ?? '[no content]',
                'message_type'           => $messageType,
                'generated_by_ai'        => false,
                'metadata'               => array_merge([
                    'raw'               => $value,
                    'post_id'           => $postId,
                    'comment_id'        => $commentId,
                    'parent_id'         => $parentId,
                    'parent_message_id' => $parentMessage?->id,
                    'is_reply'          => $isReply,
                    'post'              => $value['post']                  ?? null,
                    'permalink'         => $value['post']['permalink_url'] ?? null,
                ], $imageMeta ? ['image' => $imageMeta] : []), // 🆕,
                'published_at' => $publishedAt,
            ]
        );

        if ($message->wasRecentlyCreated) {
            Log::info('[Facebook][Feed] Nouveau commentaire entrant créé', [
                'message_id'        => $message->id,
                'conversation_id'   => $conversation->id,
                'item'              => $item,
                'is_reply'          => $isReply,
                'parent_message_id' => $parentMessage?->id,
                'from'              => $from['name'] ?? $from['id'],
            ]);

            $this->bridgeUser(
                account:            $account,
                provider:           'facebook',
                externalUserId:     $from['id'],
                displayName:        $from['name'] ?? null,
                username:           null,
                conversation:       $conversation,
                socialMessage:      $message,
                isNewConversation:  $conversation->wasRecentlyCreated,
                contextTitle:       $value['post']['story']         ?? $value['post']['permalink_url'] ?? null,
                contextDescription: $content,
            );

            SocialMessageReceivedJob::dispatch($message->id);
        }

        $this->touchConversation($conversation, $publishedAt);
    }

    // ─────────────────────────────────────────────────────────
    // FACEBOOK FEED ECHO (réponse IA via Graph API)
    // ─────────────────────────────────────────────────────────

    private function handleFeedEcho(
        SocialAccount $account,
        array         $value,
        string        $postId,
        ?string       $commentId,
        ?string       $parentId,
        bool          $isReply,
        ?string       $content,
    ): void {
        $conversation = SocialConversation::where([
            'social_account_id' => $account->id,
            'provider'          => 'facebook',
            'context_type'      => 'feed_comment',
            'context_id'        => $postId,
        ])->latest('last_message_at')->first();

        if (!$conversation) {
            Log::warning('[Facebook][Feed] Echo IA sans conversation parente trouvée', [
                'post_id'    => $postId,
                'comment_id' => $commentId,
            ]);
            return;
        }

        $parentMessage = $this->resolveParentMessage(
            parentId:           $parentId,
            contextId:          $postId,
            currentCreatedTime: $value['created_time'] ?? null,
            isReply:            $isReply,
            provider:           'facebook',
            contextMetaKey:     'post_id',
        );

        $publishedAt = isset($value['created_time'])
            ? Carbon::createFromTimestamp($value['created_time'])
            : now();

        $message = SocialMessage::firstOrCreate(
            [
                'provider'            => 'facebook',
                'external_message_id' => $commentId ?? ('fb_echo_' . ($value['created_time'] ?? uniqid())),
            ],
            [
                'social_conversation_id' => $conversation->id,
                'direction'              => 'outgoing',
                'content'                => $content ?? '[no content]',
                'message_type'           => MessageType::TEXT->value,
                'generated_by_ai'        => true,
                'metadata'               => [
                    'raw'               => $value,
                    'post_id'           => $postId,
                    'comment_id'        => $commentId,
                    'parent_id'         => $parentId,
                    'parent_message_id' => $parentMessage?->id,
                    'is_reply'          => $isReply,
                    'is_echo'           => true,
                ],
                'published_at' => $publishedAt,
            ]
        );

        if ($message->wasRecentlyCreated) {
            Log::info('[Facebook][Feed] Echo IA enregistré comme sortant', [
                'message_id'        => $message->id,
                'conversation_id'   => $conversation->id,
                'parent_message_id' => $parentMessage?->id,
            ]);
        }

        $this->touchConversation($conversation, $publishedAt);
    }

    // ═════════════════════════════════════════════════════════
    // INSTAGRAM COMMENTAIRES
    // ═════════════════════════════════════════════════════════

    private function handleInstagramComment(SocialAccount $account, array $value): void
    {
        $commentId = $value['id']                ?? null;
        $mediaId   = $value['media']['id']       ?? $value['media_id'] ?? null;
        $from      = $value['from']              ?? null;
        $text      = $value['text']              ?? null;
        $parentId  = $value['parent_id']         ?? null;
        $timestamp = $value['timestamp']         ?? null;
        $caption   = $value['media']['caption']  ?? null;

        if (!$commentId || !$mediaId || !$from || !isset($from['id'])) {
            Log::warning('[Instagram][Comment] Payload incomplet', $value);
            return;
        }

        // ✅ Echo = le compte Instagram répond
        if ($from['id'] === $account->provider_account_id) {
            $this->handleInstagramCommentEcho(
                account:   $account,
                value:     $value,
                mediaId:   $mediaId,
                commentId: $commentId,
                parentId:  $parentId,
                text:      $text,
                timestamp: $timestamp,
            );
            return;
        }

        $isReply     = $parentId !== null && $parentId !== $mediaId;
        $publishedAt = $timestamp ? Carbon::parse($timestamp) : now();

        $conversation = $this->resolveInstagramCommentConversation(
            account: $account,
            from:    $from,
            mediaId: $mediaId,
        );

        $parentMessage = $this->resolveParentMessage(
            parentId:           $parentId,
            contextId:          $mediaId,
            currentCreatedTime: $timestamp ? Carbon::parse($timestamp)->timestamp : null,
            isReply:            $isReply,
            provider:           'instagram',
            contextMetaKey:     'media_id',
        );

        $message = SocialMessage::firstOrCreate(
            [
                'provider'            => 'instagram',
                'external_message_id' => $commentId,
            ],
            [
                'social_conversation_id' => $conversation->id,
                'direction'              => 'incoming',
                'content'                => $text ?? '[no content]',
                'message_type'           => MessageType::TEXT->value,
                'generated_by_ai'        => false,
                'metadata'               => [
                    'comment_id'        => $commentId,
                    'media_id'          => $mediaId,
                    'parent_id'         => $parentId,
                    'parent_message_id' => $parentMessage?->id,
                    'is_reply'          => $isReply,
                    'from'              => $from,
                    'raw'               => $value,
                ],
                'published_at' => $publishedAt,
            ]
        );

        if ($message->wasRecentlyCreated) {
            Log::info('[Instagram][Comment] Nouveau commentaire entrant créé', [
                'message_id'        => $message->id,
                'conversation_id'   => $conversation->id,
                'from'              => $from['username'] ?? $from['id'],
                'media_id'          => $mediaId,
                'is_reply'          => $isReply,
                'parent_message_id' => $parentMessage?->id,
            ]);

            $this->bridgeUser(
                account:            $account,
                provider:           'instagram',
                externalUserId:     $from['id'],
                displayName:        $from['name'] ?? $from['username'] ?? null,
                username:           $from['username'] ?? null,
                conversation:       $conversation,
                socialMessage:      $message,
                isNewConversation:  $conversation->wasRecentlyCreated,
                contextTitle:       $caption,
                contextDescription: $text,
            );

            SocialMessageReceivedJob::dispatch($message->id);
        }

        $this->touchConversation($conversation, $publishedAt);
    }

    // ─────────────────────────────────────────────────────────
    // INSTAGRAM COMMENTAIRE ECHO (réponse IA)
    // ─────────────────────────────────────────────────────────

    private function handleInstagramCommentEcho(
        SocialAccount $account,
        array         $value,
        string        $mediaId,
        string        $commentId,
        ?string       $parentId,
        ?string       $text,
        mixed         $timestamp,
    ): void {
        $conversation = SocialConversation::where([
            'social_account_id' => $account->id,
            'provider'          => 'instagram',
            'context_type'      => 'ig_comment',
            'context_id'        => $mediaId,
        ])->latest('last_message_at')->first();

        if (!$conversation) {
            Log::warning('[Instagram][Comment] Echo IA sans conversation parente', [
                'media_id'   => $mediaId,
                'comment_id' => $commentId,
            ]);
            return;
        }

        $publishedAt = $timestamp ? Carbon::parse($timestamp) : now();

        $message = SocialMessage::firstOrCreate(
            [
                'provider'            => 'instagram',
                'external_message_id' => $commentId,
            ],
            [
                'social_conversation_id' => $conversation->id,
                'direction'              => 'outgoing',
                'content'                => $text ?? '[no content]',
                'message_type'           => MessageType::TEXT->value,
                'generated_by_ai'        => true,
                'metadata'               => [
                    'comment_id' => $commentId,
                    'media_id'   => $mediaId,
                    'parent_id'  => $parentId,
                    'is_echo'    => true,
                    'raw'        => $value,
                ],
                'published_at' => $publishedAt,
            ]
        );

        if ($message->wasRecentlyCreated) {
            Log::info('[Instagram][Comment] Echo IA enregistré comme sortant', [
                'message_id'      => $message->id,
                'conversation_id' => $conversation->id,
            ]);
        }

        $this->touchConversation($conversation, $publishedAt);
    }

    // ═════════════════════════════════════════════════════════
    // INBOX DM (Facebook Messenger + Instagram DM)
    // ═════════════════════════════════════════════════════════

    private function handleMessage(
        SocialAccount $account,
        array         $messagingEvent,
        bool          $isInstagram,
    ): void {
        $provider    = $isInstagram ? 'instagram' : 'facebook';
        $senderId    = $messagingEvent['sender']['id']    ?? null;
        $recipientId = $messagingEvent['recipient']['id'] ?? null;
        $msg         = $messagingEvent['message']         ?? null;
        $timestamp   = $messagingEvent['timestamp']       ?? null;

        if (!$senderId || !$msg) {
            Log::warning("[{$provider}][DM] Event incomplet", $messagingEvent);
            return;
        }

        $text   = $msg['text'] ?? null;
        $mid    = $msg['mid']  ?? null;
        $isEcho = ($msg['is_echo'] ?? false) || $senderId === $account->provider_account_id;
        $imageUrl = $this->extractImageAttachmentUrl($msg); // 🆕

        if ($isEcho) {
            $this->handleInboxEcho(
                account:        $account,
                provider:       $provider,
                messagingEvent: $messagingEvent,
                senderId:       $senderId,
                recipientId:    $recipientId,
                text:           $text,
                mid:            $mid,
                timestamp:      $timestamp,
            );
            return;
        }

        if (!$text && !$imageUrl) {
            Log::info("[{$provider}][DM] Message non-texte/non-image reçu", [
                'type' => $this->resolveAttachmentType($msg),
                'mid'  => $mid,
            ]);
            return;
        }

        if (!$mid) {
            Log::warning("[{$provider}][DM] \"mid\" manquant", $messagingEvent);
            return;
        }

        $publishedAt   = $timestamp ? Carbon::createFromTimestampMs($timestamp) : now();
        $contextIdHash = hash('sha256', "inbox:{$senderId}");

        $conversation = SocialConversation::firstOrCreate(
            [
                'social_account_id' => $account->id,
                'provider'          => $provider,
                'external_user_id'  => $senderId,
                'context_type'      => 'inbox',
                'context_id_hash'   => $contextIdHash,
            ],
            [
                'site_id'               => $account->site_id,
                'external_username'     => null,
                'external_display_name' => null,
                'context_type'          => 'inbox',
                'context_id'            => null,
                'context_id_hash'       => $contextIdHash,
                'source_object_id'      => null,
                'metadata'              => [
                    'sender_id'    => $senderId,
                    'recipient_id' => $recipientId,
                ],
                'last_message_at' => $publishedAt,
            ]
        );

        // 🆕 Analyse vision si image présente
        $content     = $text;
        $messageType = MessageType::TEXT->value;
        $imageMeta   = null;

        if ($imageUrl) {
            $visionResult = $this->socialImageAnalysisService->analyzeFromUrl(
                $imageUrl,
                $text,
                "meta-{$provider}-dm:{$mid}",
            );

            if ($visionResult) {
                $content     = $this->socialImageAnalysisService->buildEnrichedContent($text, $visionResult);
                $messageType = MessageType::IMAGE->value;
                $imageMeta   = $this->socialImageAnalysisService->buildMetadataBlock($visionResult, $imageUrl);
            }
        }

        $message = SocialMessage::firstOrCreate(
            [
                'provider'            => $provider,
                'external_message_id' => $mid,
            ],
            [
                'social_conversation_id' => $conversation->id,
                'direction'              => 'incoming',
                'content'                => $content,     // 🆕 était $text
                'message_type'           => $messageType, // 🆕 était MessageType::TEXT->value
                'generated_by_ai'        => false,
                'metadata'               => array_merge([
                    'raw'          => $messagingEvent,
                    'sender_id'    => $senderId,
                    'recipient_id' => $recipientId,
                    'mid'          => $mid,
                ], $imageMeta ? ['image' => $imageMeta] : []), // 🆕
                'published_at' => $publishedAt,
            ]
        );

        if ($message->wasRecentlyCreated) {
            Log::info("[{$provider}][DM] Nouveau message entrant créé", [
                'message_id'      => $message->id,
                'conversation_id' => $conversation->id,
                'sender_id'       => $senderId,
                'mid'             => $mid,
            ]);

            // ✅ DM inbox : pas de contexte de post/media disponible.
            // Le texte du message sert de contexte au LLM.
            $this->bridgeUser(
                account:            $account,
                provider:           $provider,
                externalUserId:     $senderId,
                displayName:        null, // PSID/IGSID : pas de nom dans le webhook
                username:           null,
                conversation:       $conversation,
                socialMessage:      $message,
                isNewConversation:  $conversation->wasRecentlyCreated,
                contextTitle:       null,
                contextDescription: $content,
            );

            SocialMessageReceivedJob::dispatch($message->id);
        }

        $this->touchConversation($conversation, $publishedAt);
    }

    // ─────────────────────────────────────────────────────────
    // INBOX ECHO (réponse IA via Graph API Send Message)
    // ─────────────────────────────────────────────────────────

    private function handleInboxEcho(
        SocialAccount $account,
        string        $provider,
        array         $messagingEvent,
        ?string       $senderId,
        ?string       $recipientId,
        ?string       $text,
        ?string       $mid,
        mixed         $timestamp,
    ): void {
        if (!$text || !$mid) {
            Log::info("[{$provider}][DM] Echo non-texte ignoré", ['mid' => $mid]);
            return;
        }

        // ✅ Dans un echo : sender = page/compte IA, recipient = vrai utilisateur
        $realUserId = $recipientId;

        $conversation = SocialConversation::where([
            'social_account_id' => $account->id,
            'provider'          => $provider,
            'external_user_id'  => $realUserId,
            'context_type'      => 'inbox',
        ])->whereNull('context_id')->first();

        if (!$conversation) {
            Log::warning("[{$provider}][DM] Echo IA sans conversation inbox trouvée", [
                'real_user_id' => $realUserId,
                'mid'          => $mid,
            ]);
            return;
        }

        $publishedAt = $timestamp ? Carbon::createFromTimestampMs($timestamp) : now();

        $message = SocialMessage::firstOrCreate(
            [
                'provider'            => $provider,
                'external_message_id' => $mid,
            ],
            [
                'social_conversation_id' => $conversation->id,
                'direction'              => 'outgoing',
                'content'                => $text,
                'message_type'           => MessageType::TEXT->value,
                'generated_by_ai'        => true,
                'metadata'               => [
                    'raw'          => $messagingEvent,
                    'sender_id'    => $senderId,
                    'recipient_id' => $recipientId,
                    'mid'          => $mid,
                    'is_echo'      => true,
                ],
                'published_at' => $publishedAt,
            ]
        );

        if ($message->wasRecentlyCreated) {
            Log::info("[{$provider}][DM] Echo IA enregistré comme sortant", [
                'message_id'      => $message->id,
                'conversation_id' => $conversation->id,
                'real_user_id'    => $realUserId,
                'mid'             => $mid,
            ]);
        }

        $this->touchConversation($conversation, $publishedAt);
    }

    // ─────────────────────────────────────────────────────────
    // BRIDGE USER + CONVERSATION ELCHAT
    // ─────────────────────────────────────────────────────────

    /**
     * Résout le User ELChat et déclenche le bridge Conversation + Message RAG.
     *
     * Meta (Facebook + Instagram) n'expose jamais email/phone via webhook
     * (politique RGPD Meta). L'identifiant natif est :
     *   - Facebook : PSID  (Page-Scoped ID)
     *   - Instagram : IGSID (Instagram-Scoped ID)
     *
     * @param string|null $contextTitle       Story/caption du post ou null (inbox)
     * @param string|null $contextDescription Commentaire ou texte du DM
     */
    private function bridgeUser(
        SocialAccount     $account,
        string            $provider,
        string            $externalUserId,
        ?string           $displayName,
        ?string           $username,
        SocialConversation $conversation,
        SocialMessage     $socialMessage,
        bool              $isNewConversation,
        ?string           $contextTitle,
        ?string           $contextDescription,
    ): void {
        $user = $this->userResolver->resolve(
            account:        $account,
            externalUserId: $externalUserId,
            displayName:    $displayName,
            username:       $username,
            email:          null, // Non exposé par Meta via webhook
            phone:          null, // Non exposé par Meta via webhook
        );

        if ($isNewConversation) {
            $this->conversationBridge->bridge(
                socialConv:        $conversation,
                socialMessage:     $socialMessage,
                user:              $user,
                isNewConversation: true,
                videoContext:      [
                    'title'       => $contextTitle,
                    'description' => $contextDescription
                        ? mb_substr(trim($contextDescription), 0, 300)
                        : null,
                ],
            );
        }
    }

    // ─────────────────────────────────────────────────────────
    // RESOLVE CONVERSATIONS
    // ─────────────────────────────────────────────────────────

    /**
     * 1 conversation par (user + post Facebook).
     * Qu'il commente ou réponde dans le thread, c'est la même conv.
     */
    private function resolveFeedConversation(
        SocialAccount $account,
        array         $from,
        string        $postId,
    ): SocialConversation {
        $contextIdHash = hash('sha256', $postId);

        return SocialConversation::firstOrCreate(
            [
                'social_account_id' => $account->id,
                'provider'          => 'facebook',
                'external_user_id'  => $from['id'],
                'context_type'      => 'feed_comment',
                'context_id_hash'   => $contextIdHash,
            ],
            [
                'site_id'               => $account->site_id,
                'external_username'     => $from['name'] ?? null,
                'external_display_name' => $from['name'] ?? null,
                'context_type'          => 'feed_comment',
                'context_id'            => $postId,
                'context_id_hash'       => $contextIdHash,
                'source_object_id'      => $postId,
                'metadata'              => [
                    'from'    => $from,
                    'post_id' => $postId,
                ],
                'last_message_at' => now(),
            ]
        );
    }

    /**
     * 1 conversation par (user + media Instagram).
     */
    private function resolveInstagramCommentConversation(
        SocialAccount $account,
        array         $from,
        string        $mediaId,
    ): SocialConversation {
        $contextIdHash = hash('sha256', $mediaId);

        return SocialConversation::firstOrCreate(
            [
                'social_account_id' => $account->id,
                'provider'          => 'instagram',
                'external_user_id'  => $from['id'],
                'context_type'      => 'ig_comment',
                'context_id_hash'   => $contextIdHash,
            ],
            [
                'site_id'               => $account->site_id,
                'external_username'     => $from['username'] ?? null,
                'external_display_name' => $from['name'] ?? $from['username'] ?? null,
                'context_type'          => 'ig_comment',
                'context_id'            => $mediaId,
                'context_id_hash'       => $contextIdHash,
                'source_object_id'      => $mediaId,
                'metadata'              => [
                    'from'     => $from,
                    'media_id' => $mediaId,
                ],
                'last_message_at' => now(),
            ]
        );
    }

    // ─────────────────────────────────────────────────────────
    // RESOLVE PARENT MESSAGE
    //
    // Limitation commune Facebook + Instagram :
    // parent_id pointe toujours vers le commentaire RACINE du thread,
    // jamais vers le commentaire directement ciblé.
    //
    // Stratégie : le parent logique = le dernier message du thread
    // posté AVANT le message courant (même approche que YouTube).
    // ─────────────────────────────────────────────────────────

    private function resolveParentMessage(
        ?string  $parentId,
        string   $contextId,
        int|null $currentCreatedTime,
        bool     $isReply,
        string   $provider,
        string   $contextMetaKey, // 'post_id' pour Facebook, 'media_id' pour Instagram
    ): ?SocialMessage {
        if (!$isReply || !$parentId || !$currentCreatedTime) {
            return null;
        }

        $currentPublishedAt = Carbon::createFromTimestamp($currentCreatedTime);

        // ✅ Étape 1 : ancrer dans le thread via le commentaire racine
        $rootMessage = SocialMessage::where('provider', $provider)
            ->where('external_message_id', $parentId)
            ->first();

        if (!$rootMessage) {
            Log::warning("[{$provider}] Message racine introuvable", [
                'parent_id'  => $parentId,
                'context_id' => $contextId,
            ]);
            return null;
        }

        // ✅ Étape 2 : dernier message du thread posté AVANT le courant
        $lastInThread = SocialMessage::where('provider', $provider)
            ->where(function ($q) use ($parentId, $contextId, $contextMetaKey) {
                $q->where('external_message_id', $parentId)
                    ->orWhereJsonContains("metadata->parent_id", $parentId)
                    ->orWhereJsonContains("metadata->{$contextMetaKey}", $contextId);
            })
            ->where('published_at', '<', $currentPublishedAt)
            ->orderByDesc('published_at')
            ->first();

        return $lastInThread ?? $rootMessage;
    }

    // ─────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────

    private function resolveAttachmentType(array $msg): string
    {
        if (!empty($msg['attachments'])) {
            return $msg['attachments'][0]['type'] ?? 'attachment';
        }
        if (!empty($msg['sticker_id'])) {
            return 'sticker';
        }
        return 'unknown';
    }

    /**
     * URL de l'image d'un DM Messenger/Instagram, si présente.
     * Format Meta : attachments: [{ type: 'image', payload: { url: '...' } }]
     * (identique sur Facebook Messenger et Instagram DM)
     */
    private function extractImageAttachmentUrl(array $msg): ?string
    {
        $attachment = $msg['attachments'][0] ?? null;

        if (($attachment['type'] ?? null) !== 'image') {
            return null;
        }

        return $attachment['payload']['url'] ?? null;
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
