<?php

namespace App\Services\Social\YoutTube;

use App\Enums\Social\MessageType;
use App\Exceptions\YouTubeParentNotReadyException;
use App\Jobs\social\SocialMessageReceivedJob;
use App\Models\Social\SocialAccount;
use App\Models\Social\SocialConversation;
use App\Models\Social\SocialEvent;
use App\Models\Social\SocialMessage;
use App\Services\Social\ConversationBridgeService;
use App\Services\Social\UserResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class YouTubeEventParser
{
    public function __construct(
        private readonly UserResolver               $userResolver,
        private readonly ConversationBridgeService  $conversationBridge,
    ) {}

    public function handle(SocialEvent $event): void
    {
        $payload = $event->payload;

        Log::info('[YouTube] RAW_EVENT', $payload);

        if (($event->event_type ?? null) !== 'comment_received') {
            Log::info('[YouTube] event_type ignoré', ['event_type' => $event->event_type]);
            return;
        }

        $account = SocialAccount::find($event->social_account_id);

        if (!$account) {
            Log::warning('[YouTube] SocialAccount introuvable pour l\'event', [
                'event_id'          => $event->id,
                'social_account_id' => $event->social_account_id,
            ]);
            return;
        }

        if (!$account->is_active) {
            Log::info('[YouTube] SocialAccount inactif, event ignoré', [
                'account_id' => $account->id,
            ]);
            return;
        }

        $comment = $this->normalizeComment($payload);

        if (!$comment) {
            Log::warning('[YouTube] Payload de commentaire invalide ou incomplet', $payload);
            return;
        }

        $this->handleComment($account, $comment);
    }

    // ─────────────────────────────────────────────────────────
    // NORMALIZE
    // ─────────────────────────────────────────────────────────

    private function normalizeComment(array $payload): ?array
    {
        if (!isset($payload['comment_id'], $payload['video_id'])) {
            return null;
        }

        return [
            'video_id'             => $payload['video_id'],
            'comment_id'           => $payload['comment_id'],
            'top_level_comment_id' => $payload['top_level_comment_id'] ?? $payload['comment_id'],
            'parent_comment_id'    => $payload['parent_comment_id']    ?? null,
            'author_channel_id'    => $payload['author_channel_id']    ?? null,
            'author_name'          => $payload['author_name']          ?? null,
            'message'              => $payload['message']              ?? '[no content]',
            'published_at'         => $payload['published_at']         ?? now()->toIso8601String(),
            'raw'                  => $payload['raw']                  ?? null,
            // ── Contexte vidéo transmis si disponible dans le payload ──────
            'video_title'          => $payload['video_title']          ?? null,
            'video_description'    => $payload['video_description']    ?? null,
            // ── Contact (exposé par certaines plateformes, pas YouTube) ────
            // Présent ici pour uniformiser la signature du UserResolver.
            'author_email'         => $payload['author_email']         ?? null,
            'author_phone'         => $payload['author_phone']         ?? null,
        ];
    }

    // ─────────────────────────────────────────────────────────
    // HANDLE COMMENT
    // ─────────────────────────────────────────────────────────

    private function handleComment(SocialAccount $account, array $comment): void
    {
        $authorChannelId = $comment['author_channel_id'];

        // ⚠️ Fallback pour author_channel_id null (compte supprimé/suspendu)
        if (!$authorChannelId) {
            $authorChannelId = 'unknown_' . substr(md5($comment['comment_id']), 0, 16);

            Log::warning('[YouTube] author_channel_id manquant, fallback appliqué', [
                'comment_id'  => $comment['comment_id'],
                'fallback_id' => $authorChannelId,
            ]);
        }

        $publishedAt = Carbon::parse($comment['published_at']);

        // ✅ Echo = la chaîne (l'IA) a posté ce commentaire
        $isChannelEcho = $authorChannelId === $account->provider_account_id;

        if ($isChannelEcho) {
            $this->handleEcho($account, $comment, $authorChannelId, $publishedAt);
            return;
        }

        // ── Résolution SocialConversation ─────────────────────────────────
        $conversation = $this->resolveConversation($account, $comment, $authorChannelId);

        $parentMessage = $this->resolveParentMessage($comment, $publishedAt);

        // ── Persistance SocialMessage ──────────────────────────────────────
        $message = SocialMessage::firstOrCreate(
            [
                'provider'            => 'youtube',
                'external_message_id' => $comment['comment_id'],
            ],
            [
                'social_conversation_id' => $conversation->id,
                'direction'              => 'incoming',
                'content'                => $comment['message'],
                'message_type'           => MessageType::TEXT->value,
                'generated_by_ai'        => false,
                'metadata'               => [
                    'video_id'             => $comment['video_id'],
                    'comment_id'           => $comment['comment_id'],
                    'top_level_comment_id' => $comment['top_level_comment_id'],
                    'parent_comment_id'    => $comment['parent_comment_id'],
                    'parent_message_id'    => $parentMessage?->id,
                    'is_reply'             => $comment['parent_comment_id'] !== null,
                    'author_channel_id'    => $authorChannelId,
                    'raw'                  => $comment['raw'],
                ],
                'published_at' => $publishedAt,
            ]
        );

        if ($message->wasRecentlyCreated) {
            Log::info('[YouTube] Nouveau commentaire entrant créé', [
                'message_id'        => $message->id,
                'conversation_id'   => $conversation->id,
                'video_id'          => $comment['video_id'],
                'is_reply'          => $comment['parent_comment_id'] !== null,
                'parent_message_id' => $parentMessage?->id,
                'from'              => $comment['author_name'] ?? $authorChannelId,
            ]);

            // ── Bridge User + Conversation ELChat ─────────────────────────
            // Uniquement sur le PREMIER commentaire d'une nouvelle conv
            // (wasRecentlyCreated sur SocialConversation) ou sur tout nouveau
            // SocialMessage si le User doit être résolu.
            $isNewConversation = $conversation->wasRecentlyCreated;

            // 🔑 Résolution User (create or find + attach canal)
            // On saute les "unknown_" (comptes supprimés) pour ne pas polluer
            if (!str_starts_with($authorChannelId, 'unknown_')) {
                $user = $this->userResolver->resolve(
                    account:        $account,
                    externalUserId: $authorChannelId,
                    displayName:    $comment['author_name'] ?? null,
                    username:       $comment['author_name'] ?? null,
                    email:          $comment['author_email'] ?? null,   // null sur YouTube
                    phone:          $comment['author_phone'] ?? null,   // null sur YouTube
                );

                // 🌉 Bridge ELChat (Conversation + Message RAG)
                // Déclenché uniquement sur la nouvelle conversation pour éviter
                // de dupliquer les messages dans la table messages ELChat.
                if ($isNewConversation) {
                    $this->conversationBridge->bridge(
                        socialConv:        $conversation,
                        socialMessage:     $message,
                        user:              $user,
                        isNewConversation: true,
                        videoContext:      [
                            'title'       => $comment['video_title']       ?? null,
                            'description' => $comment['video_description'] ?? null,
                        ],
                    );
                }
            }

            SocialMessageReceivedJob::dispatch($message->id);
        }

        $this->touchConversation($conversation, $publishedAt);
    }

    // ─────────────────────────────────────────────────────────
    // HANDLE ECHO (réponse IA via YouTubeChannel::sendReply())
    // ─────────────────────────────────────────────────────────

    private function handleEcho(
        SocialAccount $account,
        array          $comment,
        string         $authorChannelId,
        Carbon         $publishedAt,
    ): void {
        $conversation = SocialConversation::where([
            'social_account_id' => $account->id,
            'provider'          => 'youtube',
            'context_type'      => 'video_comment',
            'context_id'        => $comment['video_id'],
        ])->latest('last_message_at')->first();

        if (!$conversation) {
            Log::warning('[YouTube] Echo IA sans conversation parente trouvée', [
                'video_id'   => $comment['video_id'],
                'comment_id' => $comment['comment_id'],
            ]);
            return;
        }

        $parentMessage = $this->resolveParentMessage($comment, $publishedAt);

        $message = SocialMessage::firstOrCreate(
            [
                'provider'            => 'youtube',
                'external_message_id' => $comment['comment_id'],
            ],
            [
                'social_conversation_id' => $conversation->id,
                'direction'              => 'outgoing',
                'content'                => $comment['message'],
                'message_type'           => MessageType::TEXT->value,
                'generated_by_ai'        => true,
                'metadata'               => [
                    'video_id'             => $comment['video_id'],
                    'comment_id'           => $comment['comment_id'],
                    'top_level_comment_id' => $comment['top_level_comment_id'],
                    'parent_comment_id'    => $comment['parent_comment_id'],
                    'parent_message_id'    => $parentMessage?->id,
                    'is_reply'             => $comment['parent_comment_id'] !== null,
                    'is_echo'              => true,
                    'author_channel_id'    => $authorChannelId,
                    'raw'                  => $comment['raw'],
                ],
                'published_at' => $publishedAt,
            ]
        );

        if ($message->wasRecentlyCreated) {
            Log::info('[YouTube] Echo IA enregistré comme message sortant', [
                'message_id'        => $message->id,
                'conversation_id'   => $conversation->id,
                'parent_message_id' => $parentMessage?->id,
            ]);
        }

        $this->touchConversation($conversation, $publishedAt);
    }

    // ─────────────────────────────────────────────────────────
    // RESOLVE CONVERSATION — 1 conv par (user + vidéo)
    // ─────────────────────────────────────────────────────────

    private function resolveConversation(
        SocialAccount $account,
        array         $comment,
        string        $authorChannelId,
    ): SocialConversation { 
        return SocialConversation::firstOrCreate(
            [
                'social_account_id' => $account->id,
                'provider'          => 'youtube',
                'external_user_id'  => $authorChannelId,
                'context_type'      => 'video_comment',
                'context_id'        => $comment['video_id'],
                'context_id_hash'       => hash('sha256', $comment['video_id']), // ← ajout
            ],
            [
                'site_id'               => $account->site_id,
                'external_username'     => $comment['author_name'] ?? null,
                'external_display_name' => $comment['author_name'] ?? null,
                'context_type'          => 'video_comment',
                'context_id'            => $comment['video_id'],
                'context_id_hash'       => hash('sha256', $comment['video_id']), // ← ajout
                'source_object_id'      => $comment['video_id'],
                'metadata'              => [
                    'author_channel_id' => $authorChannelId,
                    'video_id'          => $comment['video_id'],
                ],
                'last_message_at' => now(),
            ]
        );
    }

    // ─────────────────────────────────────────────────────────
    // RESOLVE PARENT MESSAGE
    // ─────────────────────────────────────────────────────────

    private function resolveParentMessage(array $comment, Carbon $currentPublishedAt): ?SocialMessage
    {
        $parentId = $comment['parent_comment_id'];

        if (!$parentId) {
            return null;
        }

        $rootMessage = SocialMessage::where('provider', 'youtube')
            ->where('external_message_id', $parentId)
            ->first();

        if (!$rootMessage) {
            throw new YouTubeParentNotReadyException(
                "Commentaire racine {$parentId} introuvable pour le commentaire {$comment['comment_id']}"
            );
        }

        $lastInThread = SocialMessage::where('provider', 'youtube')
            ->where(function ($q) use ($parentId) {
                $q->where('external_message_id', $parentId)
                    ->orWhereJsonContains('metadata->top_level_comment_id', $parentId);
            })
            ->where('published_at', '<', $currentPublishedAt)
            ->orderByDesc('published_at')
            ->first();

        return $lastInThread ?? $rootMessage;
    }

    // ─────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────

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
