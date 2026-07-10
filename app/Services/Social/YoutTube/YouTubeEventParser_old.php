<?php

namespace App\Services\Social\YoutTube;

use App\Enums\Social\MessageType;
use App\Exceptions\YouTubeParentNotReadyException;
use App\Jobs\social\SocialMessageReceivedJob;
use App\Models\Social\SocialAccount;
use App\Models\Social\SocialConversation;
use App\Models\Social\SocialEvent;
use App\Models\Social\SocialMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class YouTubeEventParser
{
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
    //
    // Format réel produit par YouTubeChannelSyncService::storeComment() :
    // un SocialEvent = un commentaire (pas de batch 'items').
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
        ];
    }

    // ─────────────────────────────────────────────────────────
    // HANDLE COMMENT
    // ─────────────────────────────────────────────────────────

    private function handleComment(SocialAccount $account, array $comment): void
    {
        $authorChannelId = $comment['author_channel_id'];

        // ⚠️ YouTube peut renvoyer un author_channel_id null (compte
        // supprimé/suspendu). Fallback explicite pour éviter de fusionner
        // tous les commentaires anonymes dans une seule conversation
        // via firstOrCreate(external_user_id => null).
        if (!$authorChannelId) {
            $authorChannelId = 'unknown_' . substr(md5($comment['comment_id']), 0, 16);

            Log::warning('[YouTube] author_channel_id manquant, fallback appliqué', [
                'comment_id'    => $comment['comment_id'],
                'fallback_id'   => $authorChannelId,
            ]);
        }

        $publishedAt = Carbon::parse($comment['published_at']);

        // ✅ Echo = la chaîne (l'IA) a posté ce commentaire via Graph/Data API
        $isChannelEcho = $authorChannelId === $account->provider_account_id;

        if ($isChannelEcho) {
            $this->handleEcho($account, $comment, $authorChannelId, $publishedAt);
            return;
        }

        $conversation = $this->resolveConversation($account, $comment, $authorChannelId);

        $parentMessage = $this->resolveParentMessage($comment, $publishedAt);

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

        // ✅ Retrouver la conv vidéo la plus récente (1 conv par user + video,
        // mais l'echo n'a pas de "user" — on cible la conv active du thread)
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
            ],
            [
                'site_id'               => $account->site_id,
                'external_username'     => $comment['author_name'] ?? null,
                'external_display_name' => $comment['author_name'] ?? null,
                'context_type'          => 'video_comment',
                'context_id'            => $comment['video_id'],
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
    //
    // Comme Facebook, YouTube aplatit les réponses : TOUTES les
    // réponses à un thread ont parent_comment_id = ID du commentaire
    // RACINE, jamais l'ID de la réponse directement ciblée.
    //
    // Stratégie identique à Facebook : le parent logique = le dernier
    // message du thread posté AVANT le commentaire courant.
    //
    // ⚠️ Si le commentaire racine n'est pas encore en base (race
    // condition de queue), on lève une exception dédiée pour que
    // le job se remette en file (voir ProcessYouTubeCommentEventJob).
    // ─────────────────────────────────────────────────────────

    private function resolveParentMessage(array $comment, Carbon $currentPublishedAt): ?SocialMessage
    {
        $parentId = $comment['parent_comment_id'];

        // Commentaire de premier niveau → pas de parent
        if (!$parentId) {
            return null;
        }

        $rootMessage = SocialMessage::where('provider', 'youtube')
            ->where('external_message_id', $parentId)
            ->first();

        if (!$rootMessage) {
            // ✅ Le commentaire racine n'est pas encore traité —
            // probablement en cours de traitement par un autre worker.
            throw new YouTubeParentNotReadyException(
                "Commentaire racine {$parentId} introuvable pour le commentaire {$comment['comment_id']}"
            );
        }

        // Dernier message du thread (racine + réponses) posté avant le courant
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
        // ✅ Ne recule jamais last_message_at : un sync peut ramener
        // des commentaires anciens après des récents.
        $current = $conversation->last_message_at
            ? Carbon::parse($conversation->last_message_at)
            : null;

        if (!$current || $publishedAt->greaterThan($current)) {
            $conversation->update(['last_message_at' => $publishedAt]);
        }
    }
}
