<?php

namespace App\Services\Social\Instagram;

use App\Enums\Social\MessageDirection;
use App\Enums\Social\MessageType;
use App\Enums\Social\SocialProvider;
use App\Jobs\social\SocialMessageReceivedJob;
use App\Models\Social\SocialAccount;
use App\Models\Social\SocialConversation;
use App\Models\Social\SocialEvent;
use App\Models\Social\SocialMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class InstagramEventParser
{
    public function handle(SocialEvent $event): void
    {
        $payload = $event->payload;

        Log::info('[Instagram] RAW_PAYLOAD', $payload);

        if (!isset($payload['object'], $payload['entry']) || $payload['object'] !== 'instagram') {
            Log::warning('[Instagram] Payload non reconnu', $payload);
            return;
        }

        foreach ($payload['entry'] as $entry) {

            $instagramAccountId = $entry['id'] ?? null;

            if (!$instagramAccountId) {
                continue;
            }

            $account = $this->resolveAccount($instagramAccountId);

            if (!$account) {
                continue;
            }

            // 📨 DIRECT MESSAGES
            foreach ($entry['messaging'] ?? [] as $messaging) {
                $this->processDirectMessage($account, $messaging);
            }

            // 💬 COMMENTS
            foreach ($entry['changes'] ?? [] as $change) {

                if (($change['field'] ?? null) !== 'comments') {
                    continue;
                }

                $this->processComment($account, $change['value'] ?? []);
            }
        }
    }

    // ─────────────────────────────────────────────────────────
    // RESOLVE ACCOUNT
    // ─────────────────────────────────────────────────────────

    private function resolveAccount(string $instagramAccountId): ?SocialAccount
    {
        $account = SocialAccount::where('provider', SocialProvider::INSTAGRAM->value)
            ->where('provider_account_id', $instagramAccountId)
            ->where('is_active', true)
            ->first();

        if (!$account) {
            Log::warning('[Instagram] SocialAccount introuvable ou inactif', [
                'instagram_id' => $instagramAccountId,
            ]);
        }

        return $account;
    }

    // ─────────────────────────────────────────────────────────
    // DIRECT MESSAGES
    // ─────────────────────────────────────────────────────────

    private function processDirectMessage(SocialAccount $account, array $messaging): void
    {
        $senderId    = data_get($messaging, 'sender.id');
        $recipientId = data_get($messaging, 'recipient.id');
        $messageText = data_get($messaging, 'message.text');
        $messageId   = data_get($messaging, 'message.mid');
        $isEcho      = data_get($messaging, 'message.is_echo', false);
        $timestamp   = data_get($messaging, 'timestamp');

        if (!$senderId || !$messageText || !$messageId) {
            Log::warning('[Instagram][DM] Event incomplet', $messaging);
            return;
        }

        $publishedAt = $timestamp
            ? Carbon::createFromTimestampMs($timestamp)
            : now();

        // ✅ Echo = message envoyé par le compte IG lui-même (réponse IA)
        if ($isEcho || $senderId === $account->provider_account_id) {
            $this->handleDirectMessageEcho($account, $messaging, $senderId, $recipientId, $messageText, $messageId, $publishedAt);
            return;
        }

        $conversation = SocialConversation::firstOrCreate(
            [
                'social_account_id' => $account->id,
                'provider'          => SocialProvider::INSTAGRAM->value,
                'external_user_id'  => $senderId,
                'context_type'      => 'inbox',
                'context_id'        => null,
            ],
            [
                'site_id'               => $account->site_id,
                'external_username'     => null,
                'external_display_name' => null,
                'context_type'          => 'inbox',
                'context_id'            => null,
                'source_object_id'      => null,
                'metadata' => [
                    'sender_id'    => $senderId,
                    'recipient_id' => $recipientId,
                ],
                'last_message_at' => $publishedAt,
            ]
        );

        $message = SocialMessage::firstOrCreate(
            [
                'provider'            => SocialProvider::INSTAGRAM->value,
                'external_message_id' => $messageId,
            ],
            [
                'social_conversation_id' => $conversation->id,
                'direction'              => 'incoming',
                'content'                => $messageText,
                'message_type'           => MessageType::TEXT->value,
                'generated_by_ai'        => false,
                'metadata' => [
                    'raw'          => $messaging,
                    'sender_id'    => $senderId,
                    'recipient_id' => $recipientId,
                    'mid'          => $messageId,
                ],
                'published_at' => $publishedAt,
            ]
        );

        if ($message->wasRecentlyCreated) {
            Log::info('[Instagram][DM] Nouveau message entrant créé', [
                'message_id'      => $message->id,
                'conversation_id' => $conversation->id,
                'sender_id'       => $senderId,
            ]);

            SocialMessageReceivedJob::dispatch($message->id);
        }

        $this->touchConversation($conversation, $publishedAt);
    }

    private function handleDirectMessageEcho(
        SocialAccount $account,
        array         $messaging,
        string        $senderId,
        ?string        $recipientId,
        string        $messageText,
        string        $messageId,
        Carbon        $publishedAt,
    ): void {

        // ✅ Dans un echo, sender = compte IG, recipient = vrai utilisateur
        $realUserId = $recipientId;

        $conversation = SocialConversation::where([
            'social_account_id' => $account->id,
            'provider'          => SocialProvider::INSTAGRAM->value,
            'external_user_id'  => $realUserId,
            'context_type'      => 'inbox',
        ])->whereNull('context_id')->first();

        if (!$conversation) {
            Log::warning('[Instagram][DM] Echo IA sans conversation inbox trouvée', [
                'real_user_id' => $realUserId,
                'mid'          => $messageId,
            ]);
            return;
        }

        $message = SocialMessage::firstOrCreate(
            [
                'provider'            => SocialProvider::INSTAGRAM->value,
                'external_message_id' => $messageId,
            ],
            [
                'social_conversation_id' => $conversation->id,
                'direction'              => 'outgoing',
                'content'                => $messageText,
                'message_type'           => MessageType::TEXT->value,
                'generated_by_ai'        => true,
                'metadata' => [
                    'raw'          => $messaging,
                    'sender_id'    => $senderId,
                    'recipient_id' => $recipientId,
                    'mid'          => $messageId,
                    'is_echo'      => true,
                ],
                'published_at' => $publishedAt,
            ]
        );

        if ($message->wasRecentlyCreated) {
            Log::info('[Instagram][DM] Echo IA enregistré comme message sortant', [
                'message_id'      => $message->id,
                'conversation_id' => $conversation->id,
            ]);
        }

        $this->touchConversation($conversation, $publishedAt);
    }

    // ─────────────────────────────────────────────────────────
    // COMMENTS
    // ─────────────────────────────────────────────────────────

    private function processComment(SocialAccount $account, array $value): void
    {
        $commentId = $value['id']   ?? null;
        $text      = $value['text'] ?? null;
        $from      = $value['from'] ?? null;
        $parentId  = $value['parent_id'] ?? null;

        $mediaId = $value['media']['id'] ?? $value['media_id'] ?? null;

        if (!$commentId || !$mediaId) {
            Log::warning('[Instagram][Comment] Event incomplet', $value);
            return;
        }

        $publishedAt = isset($value['timestamp'])
            ? Carbon::parse($value['timestamp'])
            : now();

        $authorId = $from['id'] ?? null;

        // ✅ Echo = commentaire posté par le compte IG lui-même (réponse IA)
        $isAccountEcho = $authorId && $authorId === $account->provider_account_id;

        if ($isAccountEcho) {
            $this->handleCommentEcho($account, $value, $mediaId, $commentId, $parentId, $text, $publishedAt);
            return;
        }

        if (!$authorId || !$text) {
            Log::warning('[Instagram][Comment] "from" ou "text" manquant', $value);
            return;
        }

        $conversation = $this->resolveConversation($account, $authorId, $from['username'] ?? null, $mediaId);

        $parentMessage = $this->resolveParentMessage($parentId, $publishedAt);

        $message = SocialMessage::firstOrCreate(
            [
                'provider'            => SocialProvider::INSTAGRAM->value,
                'external_message_id' => $commentId,
            ],
            [
                'social_conversation_id' => $conversation->id,
                'direction'              => 'incoming',
                'content'                => $text,
                'message_type'           => MessageType::TEXT->value,
                'generated_by_ai'        => false,
                'metadata' => [
                    'raw'               => $value,
                    'media_id'          => $mediaId,
                    'comment_id'        => $commentId,
                    'parent_id'         => $parentId,
                    'parent_message_id' => $parentMessage?->id,
                    'is_reply'          => $parentId !== null,
                ],
                'published_at' => $publishedAt,
            ]
        );

        if ($message->wasRecentlyCreated) {
            Log::info('[Instagram][Comment] Nouveau commentaire entrant créé', [
                'message_id'        => $message->id,
                'conversation_id'   => $conversation->id,
                'media_id'          => $mediaId,
                'is_reply'          => $parentId !== null,
                'parent_message_id' => $parentMessage?->id,
                'from'              => $from['username'] ?? $authorId,
            ]);

            SocialMessageReceivedJob::dispatch($message->id);
        }

        $this->touchConversation($conversation, $publishedAt);
    }

    private function handleCommentEcho(
        SocialAccount $account,
        array         $value,
        string        $mediaId,
        string        $commentId,
        ?string       $parentId,
        ?string       $text,
        Carbon        $publishedAt,
    ): void {

        $conversation = SocialConversation::where([
            'social_account_id' => $account->id,
            'provider'          => SocialProvider::INSTAGRAM->value,
            'context_type'      => 'media_comment',
            'context_id'        => $mediaId,
        ])->latest('last_message_at')->first();

        if (!$conversation) {
            Log::warning('[Instagram][Comment] Echo IA sans conversation parente trouvée', [
                'media_id'   => $mediaId,
                'comment_id' => $commentId,
            ]);
            return;
        }

        $parentMessage = $this->resolveParentMessage($parentId, $publishedAt);

        $message = SocialMessage::firstOrCreate(
            [
                'provider'            => SocialProvider::INSTAGRAM->value,
                'external_message_id' => $commentId,
            ],
            [
                'social_conversation_id' => $conversation->id,
                'direction'              => 'outgoing',
                'content'                => $text ?? '[no content]',
                'message_type'           => MessageType::TEXT->value,
                'generated_by_ai'        => true,
                'metadata' => [
                    'raw'               => $value,
                    'media_id'          => $mediaId,
                    'comment_id'        => $commentId,
                    'parent_id'         => $parentId,
                    'parent_message_id' => $parentMessage?->id,
                    'is_reply'          => $parentId !== null,
                    'is_echo'           => true,
                ],
                'published_at' => $publishedAt,
            ]
        );

        if ($message->wasRecentlyCreated) {
            Log::info('[Instagram][Comment] Echo IA enregistré comme message sortant', [
                'message_id'        => $message->id,
                'conversation_id'   => $conversation->id,
                'parent_message_id' => $parentMessage?->id,
            ]);
        }

        $this->touchConversation($conversation, $publishedAt);
    }

    // ─────────────────────────────────────────────────────────
    // RESOLVE CONVERSATION — 1 conv par (user + media)
    // ─────────────────────────────────────────────────────────

    private function resolveConversation(
        SocialAccount $account,
        string        $authorId,
        ?string       $username,
        string        $mediaId,
    ): SocialConversation {

        return SocialConversation::firstOrCreate(
            [
                'social_account_id' => $account->id,
                'provider'          => SocialProvider::INSTAGRAM->value,
                'external_user_id'  => $authorId,
                'context_type'      => 'media_comment',
                'context_id'        => $mediaId,
            ],
            [
                'site_id'               => $account->site_id,
                'external_username'     => $username,
                'external_display_name' => $username,
                'context_type'          => 'media_comment',
                'context_id'            => $mediaId,
                'source_object_id'      => $mediaId,
                'metadata' => [
                    'author_id' => $authorId,
                    'media_id'  => $mediaId,
                ],
                'last_message_at' => now(),
            ]
        );
    }

    // ─────────────────────────────────────────────────────────
    // RESOLVE PARENT MESSAGE
    //
    // Comme Facebook/YouTube, Instagram aplatit les réponses :
    // parent_id pointe vers le commentaire RACINE du thread.
    // Stratégie : dernier message du thread avant le courant.
    // ─────────────────────────────────────────────────────────

    private function resolveParentMessage(?string $parentId, Carbon $currentPublishedAt): ?SocialMessage
    {
        if (!$parentId) {
            return null;
        }

        $rootMessage = SocialMessage::where('provider', SocialProvider::INSTAGRAM->value)
            ->where('external_message_id', $parentId)
            ->first();

        if (!$rootMessage) {
            Log::warning('[Instagram][Comment] Message racine introuvable', [
                'parent_id' => $parentId,
            ]);
            return null;
        }

        $lastInThread = SocialMessage::where('provider', SocialProvider::INSTAGRAM->value)
            ->where(function ($q) use ($parentId) {
                $q->where('external_message_id', $parentId)
                    ->orWhereJsonContains('metadata->parent_id', $parentId);
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
