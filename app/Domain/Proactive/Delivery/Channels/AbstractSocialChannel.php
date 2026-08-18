<?php

namespace App\Domain\Proactive\Delivery\Channels;

use App\Domain\Proactive\Contracts\DeliveryChannel;
use App\Enums\Social\MessageDirection;
use App\Enums\Social\MessageType;
use App\Enums\Social\ReplyStatus;
use App\Models\Proactive\ProactiveMessage;
use App\Models\Social\SocialConversation;
use App\Models\Social\SocialConversationLink;
use App\Models\Social\SocialMessage;
use App\Models\Social\SocialReplyQueue;
use App\Services\Social\SocialReplyDispatcher;

abstract class AbstractSocialChannel implements DeliveryChannel
{
    /** @param list<string> $providers */
    public function __construct(
        private readonly SocialReplyDispatcher $dispatcher,
        private readonly array $providers,
    ) {}

    public function canSend(ProactiveMessage $message): array
    {
        if (!config("proactive.channels.{$this->channel()}.enabled", false)) {
            return ['allowed' => false, 'reason' => 'channel_disabled'];
        }

        $conversation = $this->resolveConversation($message);
        $provider = $conversation?->provider;
        $provider = $provider instanceof \BackedEnum ? $provider->value : (string) $provider;

        if (!$conversation || !in_array($provider, $this->providers, true)) {
            return ['allowed' => false, 'reason' => 'social_conversation_unavailable'];
        }

        if (!$conversation->socialAccount?->is_active) {
            return ['allowed' => false, 'reason' => 'social_account_inactive'];
        }

        $windowHours = config("proactive.channels.{$this->channel()}.window_hours");
        if ($windowHours && (!$conversation->last_message_at || $conversation->last_message_at->lt(now()->subHours((int) $windowHours)))) {
            return ['allowed' => false, 'reason' => 'provider_messaging_window_closed'];
        }

        return ['allowed' => true, 'reason' => null];
    }

    public function deliver(ProactiveMessage $message): array
    {
        $conversation = $this->resolveConversation($message);
        if (!$conversation) {
            throw new \RuntimeException('Social conversation unavailable.');
        }

        $socialMessage = $message->social_message_id
            ? SocialMessage::query()->find($message->social_message_id)
            : null;

        if (!$socialMessage) {
            $socialMessage = SocialMessage::create([
                'social_conversation_id' => $conversation->id,
                'provider' => $conversation->provider,
                'direction' => MessageDirection::OUTGOING,
                'content' => (string) $message->content,
                'message_type' => MessageType::TEXT,
                'generated_by_ai' => true,
                'metadata' => ['proactive_message_id' => $message->id],
            ]);
            $message->forceFill(['social_message_id' => $socialMessage->id])->save();
        }

        $reply = SocialReplyQueue::query()->firstOrCreate(
            ['social_message_id' => $socialMessage->id],
            ['status' => ReplyStatus::PENDING->value, 'attempts' => 0],
        );

        if (!in_array((string) $reply->status, [ReplyStatus::APPROVED->value, ReplyStatus::PROCESSING->value, ReplyStatus::PUBLISHED->value], true)) {
            $this->dispatcher->dispatch($reply);
        }

        $provider = $conversation->provider instanceof \BackedEnum
            ? $conversation->provider->value
            : (string) $conversation->provider;

        return [
            'accepted' => true,
            'provider' => $provider,
            'external_message_id' => $socialMessage->external_message_id,
            // L'acceptation signifie ici « remis à la queue du provider », pas
            // « livré à l'utilisateur ». Le job social publiera l'état réel.
            'details' => ['reply_queue_id' => $reply->id, 'social_message_id' => $socialMessage->id, 'delivered' => false],
        ];
    }

    protected function resolveConversation(ProactiveMessage $message): ?SocialConversation
    {
        $sequence = $message->relationLoaded('sequence') ? $message->sequence : $message->sequence()->first();
        $socialId = $sequence?->social_conversation_id;

        if (!$socialId && $message->conversation_id) {
            $socialId = SocialConversationLink::query()
                ->where('conversation_id', $message->conversation_id)
                ->value('social_conversation_id');
        }

        return $socialId
            ? SocialConversation::query()->with('socialAccount')->where('site_id', $message->site_id)->find($socialId)
            : null;
    }
}
