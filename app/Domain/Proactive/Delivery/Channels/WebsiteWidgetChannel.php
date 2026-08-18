<?php

namespace App\Domain\Proactive\Delivery\Channels;

use App\Domain\Proactive\Contracts\DeliveryChannel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Proactive\ProactiveMessage;
use App\Services\MercureService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WebsiteWidgetChannel implements DeliveryChannel
{
    public function __construct(private readonly MercureService $mercure) {}

    public function channel(): string
    {
        return 'website';
    }

    public function canSend(ProactiveMessage $message): array
    {
        $conversation = Conversation::query()
            ->whereKey($message->conversation_id)
            ->where('site_id', $message->site_id)
            ->first();

        if (! $conversation || ! $conversation->visitor_id || $conversation->visitor_id !== $message->visitor_id) {
            return ['allowed' => false, 'reason' => 'website_conversation_unavailable'];
        }

        return ['allowed' => true, 'reason' => null];
    }

    public function deliver(ProactiveMessage $message): array
    {
        $message->loadMissing(['campaign:id,widget_behavior,priority', 'visitor:id,uuid']);

        $chatMessage = $message->message_id
            ? Message::query()->find($message->message_id)
            : null;

        if (! $chatMessage) {
            $chatMessage = Message::create([
                'id' => (string) Str::uuid(),
                'conversation_id' => $message->conversation_id,
                'role' => 'bot',
                'content' => (string) $message->content,
            ]);
            $message->forceFill(['message_id' => $chatMessage->id])->save();
        }

        try {
            $this->mercure->post("/sites/{$message->site_id}/conversations/{$message->conversation_id}", [
                'type' => 'bot_message',
                'conversation_id' => $message->conversation_id,
                'message_id' => $chatMessage->id,
                'proactive_message_id' => $message->id,
                'content' => $chatMessage->content,
                'ctas' => [],
                'entities' => [],
                'suggested_actions' => [],
                'created_at' => $chatMessage->created_at?->toISOString() ?? now()->toISOString(),
            ]);
        } catch (\Throwable $exception) {
            // Le message est déjà durablement stocké et sera visible à la reprise.
            Log::warning('Proactive widget Mercure notification failed.', [
                'proactive_message_id' => $message->id,
                'error' => $exception->getMessage(),
            ]);
        }

        // Le loader parent doit pouvoir détecter un message alors que
        // l'iframe n'est pas encore ouverte. Le topic de conversation ne
        // suffit pas : chat.component ne s'y abonne qu'après l'ouverture.
        if ($message->visitor?->uuid) {
            try {
                $this->mercure->post("/sites/{$message->site_id}/visitors/{$message->visitor->uuid}/proactive", [
                    'type' => 'proactive_message',
                    'site_id' => $message->site_id,
                    'visitor_uuid' => $message->visitor->uuid,
                    'conversation_id' => $message->conversation_id,
                    'message_id' => $chatMessage->id,
                    'proactive_message_id' => $message->id,
                    'behavior' => $message->campaign?->widget_behavior ?? 'notification_only',
                    'priority' => $message->campaign?->priority ?? 5,
                    'scheduled_at' => $message->scheduled_at?->toISOString(),
                    'sent_at' => now()->toISOString(),
                ]);
            } catch (\Throwable $exception) {
                Log::warning('Proactive visitor Mercure notification failed.', [
                    'proactive_message_id' => $message->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return [
            'accepted' => true,
            'provider' => 'widget',
            'external_message_id' => $chatMessage->id,
            // Le message est durablement présent dans le fil et peut être
            // considéré livré côté widget dès que Mercure a été tenté. La
            // reprise HTTP reste possible si le navigateur était absent.
            'details' => ['conversation_id' => $message->conversation_id, 'delivered' => true],
        ];
    }
}
