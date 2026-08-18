<?php

namespace App\Domain\Proactive;

use App\Models\AnalyticsEvent;
use App\Models\Proactive\ProactiveOutcome;
use App\Models\Proactive\ProactiveSequence;
use App\Services\analytics\AnalyticsEventService;
use App\Enums\AnalyticsEventType;

class ProactiveOutcomeService
{
    public function __construct(
        private readonly ProactiveAuditService $audit,
        private readonly AnalyticsEventService $analytics,
    ) {}

    public function handle(AnalyticsEvent $event): void
    {
        $isReply = $event->event_type === 'message_sent' && $event->source !== 'proactive';
        $isConversion = in_array($event->event_type, config('proactive.conversion_events', []), true);
        $isHandoff = in_array($event->event_type, config('proactive.human_handoff_events', []), true);
        $isRefusal = in_array($event->event_type, config('proactive.refusal_events', []), true);

        if (!$isReply && !$isConversion && !$isHandoff && !$isRefusal) return;

        $socialConversationId = data_get($event->metadata, 'social_conversation_id');
        if (!$event->conversation_id && !$event->visitor_id && !$socialConversationId) return;

        $sequences = ProactiveSequence::query()
            ->with(['campaign', 'messages'])
            ->where('site_id', $event->site_id)
            ->whereIn('status', ['active', 'completed', 'replied', 'converted', 'stopped'])
            ->where(function ($query) use ($event, $socialConversationId) {
                if ($event->conversation_id) $query->orWhere('conversation_id', $event->conversation_id);
                if ($event->visitor_id) $query->orWhere('visitor_id', $event->visitor_id);
                if ($socialConversationId) $query->orWhere('social_conversation_id', $socialConversationId);
            })
            ->where('last_sent_at', '>=', now()->subHours((int) config('proactive.outcome_window_hours', 168)))
            ->get();

        foreach ($sequences as $sequence) {
            if (!$sequence->last_sent_at || ($event->occurred_at && $event->occurred_at->lt($sequence->last_sent_at))) continue;

            $campaign = $sequence->campaign;
            $latestMessage = $sequence->messages->sortByDesc('sent_at')->first();
            $stopReason = null;

            $outcomeKey = hash('sha256', "{$sequence->id}|{$event->id}|{$event->event_type}");
            $outcome = ProactiveOutcome::query()->firstOrCreate(
                ['site_id' => $sequence->site_id, 'idempotency_key' => $outcomeKey],
                [
                    'account_id' => $sequence->account_id, 'campaign_id' => $sequence->campaign_id,
                    'sequence_id' => $sequence->id, 'message_id' => $latestMessage?->id,
                    'analytics_event_id' => $event->id, 'outcome_type' => $event->event_type,
                    'attribution_type' => $isConversion ? 'assisted' : 'direct',
                    // La valeur provient exclusivement de l'événement source.
                    'value' => $event->value, 'currency' => $event->currency,
                    'occurred_at' => $event->occurred_at ?? now(), 'metadata' => [
                        'source_event_type' => $event->event_type,
                        'source' => $event->source,
                    ],
                ],
            );

            if ($outcome->wasRecentlyCreated) {
                $this->recordOutcomeEvent($event, $sequence, $latestMessage, $isConversion);
            }

            if ($isReply && $campaign->stop_on_reply && $sequence->status === 'active') {
                $sequence->update(['status' => 'replied', 'replied_at' => $event->occurred_at, 'stopped_at' => now(), 'stop_reason' => 'visitor_replied', 'next_scheduled_at' => null]);
                $latestMessage?->update(['replied_at' => $event->occurred_at]);
                $stopReason = 'visitor_replied';
            } elseif ($isConversion && $campaign->stop_on_conversion && $sequence->status === 'active') {
                $sequence->update(['status' => 'converted', 'converted_at' => $event->occurred_at, 'stopped_at' => now(), 'stop_reason' => 'conversion', 'next_scheduled_at' => null]);
                $stopReason = 'conversion';
            } elseif ($isHandoff && $campaign->stop_on_human_handoff && $sequence->status === 'active') {
                $sequence->update(['status' => 'stopped', 'stopped_at' => now(), 'stop_reason' => 'human_handoff', 'next_scheduled_at' => null]);
                $stopReason = 'human_handoff';
            } elseif ($isRefusal && ($campaign->stop_on_refusal || $campaign->stop_on_unsubscribe) && $sequence->status === 'active') {
                $sequence->update(['status' => 'stopped', 'stopped_at' => now(), 'stop_reason' => $event->event_type, 'next_scheduled_at' => null]);
                $stopReason = $event->event_type;
            }

            if (!$stopReason) continue;

            $sequence->messages()
                ->whereIn('status', ['scheduled', 'retrying'])
                ->update([
                    'status' => 'canceled',
                    'canceled_at' => now(),
                    'failure_code' => 'sequence_stopped',
                ]);

            $this->audit->record('sequence_stopped', [
                'account_id' => $sequence->account_id, 'site_id' => $sequence->site_id,
                'campaign_id' => $sequence->campaign_id, 'sequence_id' => $sequence->id,
                'message_id' => $latestMessage?->id,
            ], $stopReason, metadata: ['analytics_event_id' => $event->id]);
            if ($campaign?->site) {
                $this->analytics->capture(
                    $campaign->site,
                    AnalyticsEventType::PROACTIVE_SEQUENCE_STOPPED,
                    [
                        'visitor_id' => $sequence->visitor_id,
                        'conversation_id' => $sequence->conversation_id,
                        'message_id' => $latestMessage?->message_id,
                        'source' => 'proactive',
                        'channel' => $sequence->channel,
                        'resource_type' => 'proactive_sequence',
                        'resource_id' => $sequence->id,
                        'causation_id' => $event->id,
                    ],
                    ['campaign_id' => $sequence->campaign_id, 'sequence_id' => $sequence->id, 'reason' => $stopReason],
                    $this->analytics->deterministicKey('proactive_sequence_stopped', $sequence->id, $stopReason),
                );
            }
        }
    }

    private function recordOutcomeEvent(AnalyticsEvent $event, ProactiveSequence $sequence, $message, bool $conversion): void
    {
        $type = match (true) {
            $event->event_type === 'message_sent' => AnalyticsEventType::PROACTIVE_MESSAGE_REPLIED,
            $event->event_type === 'lead_created' => AnalyticsEventType::PROACTIVE_LEAD_CREATED,
            $event->event_type === 'meeting_booked' => AnalyticsEventType::PROACTIVE_MEETING_BOOKED,
            $event->event_type === 'opportunity_created' => AnalyticsEventType::PROACTIVE_OPPORTUNITY_CREATED,
            $event->event_type === 'purchase_completed' || $event->event_type === 'opportunity_won' => AnalyticsEventType::PROACTIVE_SALE_ATTRIBUTED,
            default => $conversion ? AnalyticsEventType::PROACTIVE_CONVERSION : null,
        };
        if (!$type) return;

        $site = $sequence->campaign?->site;
        if (!$site) return;

        $this->analytics->capture(
            $site,
            $type,
            [
                'visitor_id' => $event->visitor_id,
                'conversation_id' => $event->conversation_id,
                'message_id' => $message?->message_id,
                'source' => 'proactive',
                'channel' => $event->channel,
                'resource_type' => 'proactive_sequence',
                'resource_id' => $sequence->id,
                'value' => $event->value,
                'currency' => $event->currency,
                'causation_id' => $event->id,
            ],
            ['campaign_id' => $sequence->campaign_id, 'sequence_id' => $sequence->id, 'analytics_event_id' => $event->id],
            $this->analytics->deterministicKey($type->value, $sequence->id, $event->id),
        );

        if ($conversion && $type !== AnalyticsEventType::PROACTIVE_CONVERSION) {
            $this->analytics->capture(
                $site,
                AnalyticsEventType::PROACTIVE_CONVERSION,
                [
                    'visitor_id' => $event->visitor_id,
                    'conversation_id' => $event->conversation_id,
                    'message_id' => $message?->message_id,
                    'source' => 'proactive',
                    'channel' => $event->channel,
                    'resource_type' => 'proactive_sequence',
                    'resource_id' => $sequence->id,
                    'value' => $event->value,
                    'currency' => $event->currency,
                    'causation_id' => $event->id,
                ],
                ['campaign_id' => $sequence->campaign_id, 'sequence_id' => $sequence->id, 'analytics_event_id' => $event->id, 'conversion_event' => $event->event_type],
                $this->analytics->deterministicKey('proactive_conversion', $sequence->id, $event->id),
            );
        }
    }
}
