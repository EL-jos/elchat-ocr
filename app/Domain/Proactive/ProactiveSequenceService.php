<?php

namespace App\Domain\Proactive;

use App\Jobs\Proactive\SendProactiveMessageJob;
use App\Models\AnalyticsEvent;
use App\Models\Conversation;
use App\Models\Proactive\ProactiveCampaign;
use App\Models\Proactive\ProactiveMessage;
use App\Models\Proactive\ProactiveSequence;
use App\Models\Proactive\ProactiveTrigger;
use App\Models\Social\SocialConversation;
use App\Models\Social\SocialConversationLink;
use App\Services\analytics\AnalyticsEventService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ProactiveSequenceService
{
    public function __construct(
        private readonly ProactiveConditionEvaluator $conditions,
        private readonly ProactiveScheduleService $schedule,
        private readonly ProactiveAuditService $audit,
        private readonly AnalyticsEventService $analytics,
    ) {}

    public function evaluateEvent(AnalyticsEvent $event): array
    {
        // Les événements émis par le moteur lui-même ne doivent jamais
        // ré-entrer dans l'évaluation des triggers (Agent → message → Agent).
        if (str_starts_with($event->event_type, 'proactive_') || $event->source === 'proactive' || data_get($event->metadata, 'proactive_message_id')) return [];

        $triggers = ProactiveTrigger::query()
            ->with(['campaign.agent'])
            ->where('event_type', $event->event_type)
            ->where('is_active', true)
            ->whereHas('campaign', fn ($query) => $query->where('site_id', $event->site_id)->where('status', 'active'))
            ->orderByDesc('priority')
            ->get();

        $context = $this->eventContext($event);
        $created = [];

        foreach ($triggers as $trigger) {
            if (!$this->conditions->evaluate($trigger->conditions ?: [], $context, $trigger->condition_mode ?: 'all')) continue;

            $this->analytics->capture(
                $event->site,
                \App\Enums\AnalyticsEventType::PROACTIVE_TRIGGER_DETECTED,
                [
                    'visitor_id' => $event->visitor_id,
                    'conversation_id' => $event->conversation_id,
                    'source' => 'proactive',
                    'channel' => $event->channel,
                    'resource_type' => 'proactive_trigger',
                    'resource_id' => $trigger->id,
                    'causation_id' => $event->id,
                ],
                ['campaign_id' => $trigger->campaign_id, 'trigger_id' => $trigger->id, 'analytics_event_id' => $event->id],
                $this->analytics->deterministicKey('proactive_trigger_detected', $trigger->id, $event->id),
            );

            $this->analytics->capture(
                $event->site,
                \App\Enums\AnalyticsEventType::PROACTIVE_TRIGGER_MATCHED,
                [
                    'visitor_id' => $event->visitor_id,
                    'conversation_id' => $event->conversation_id,
                    'source' => 'proactive',
                    'channel' => $event->channel,
                    'resource_type' => 'proactive_trigger',
                    'resource_id' => $trigger->id,
                    'causation_id' => $event->id,
                ],
                ['campaign_id' => $trigger->campaign_id, 'trigger_id' => $trigger->id, 'analytics_event_id' => $event->id],
                $this->analytics->deterministicKey('proactive_trigger_matched', $trigger->id, $event->id),
            );

            $message = $this->createFromTrigger($trigger, $event);
            if ($message) $created[] = $message->id;
        }

        return $created;
    }

    public function scheduleManual(ProactiveCampaign $campaign, array $target, ?string $content = null, ?string $idempotencyKey = null): ProactiveMessage
    {
        $conversation = !empty($target['conversation_id'])
            ? Conversation::query()->where('site_id', $campaign->site_id)->findOrFail($target['conversation_id'])
            : null;
        $visitorId = $target['visitor_id'] ?? $conversation?->visitor_id;
        $socialId = $target['social_conversation_id'] ?? null;

        abort_unless($conversation || $socialId, 422, 'Une conversation existante est requise.');

        if ($campaign->channel === 'website') {
            abort_unless($conversation, 422, 'Le canal website exige une conversation ELChat existante.');
        } elseif (!$socialId && $conversation) {
            $socialId = SocialConversationLink::query()->where('conversation_id', $conversation->id)->value('social_conversation_id');
            abort_unless($socialId, 422, 'La conversation n’est pas reliée à une conversation sociale.');
        }

        if ($conversation) {
            abort_unless($conversation->site_id === $campaign->site_id, 404);
            if ($visitorId) {
                abort_unless($conversation->visitor_id === $visitorId, 422, 'Le visiteur ne correspond pas à la conversation.');
            }
        }
        if ($socialId) {
            $social = SocialConversation::query()->where('site_id', $campaign->site_id)->find($socialId);
            abort_unless($social, 404, 'La conversation sociale n’appartient pas à ce site.');
        }

        $idempotencyKey ??= hash('sha256', implode('|', ['manual', $campaign->id, $conversation?->id ?? '-', $socialId ?? '-', now()->format('YmdHi')]));
        $scheduledAt = $this->schedule->nextAllowedAt($campaign, CarbonImmutable::parse($target['scheduled_at'] ?? now()));

        $message = DB::transaction(function () use ($campaign, $conversation, $visitorId, $socialId, $content, $idempotencyKey, $scheduledAt) {
            $sequence = ProactiveSequence::query()->firstOrCreate(
                ['site_id' => $campaign->site_id, 'idempotency_key' => $idempotencyKey],
                [
                    'account_id' => $campaign->account_id,
                    'campaign_id' => $campaign->id,
                    'conversation_id' => $conversation?->id,
                    'visitor_id' => $visitorId,
                    'social_conversation_id' => $socialId,
                    'channel' => $campaign->channel,
                    'status' => 'active',
                    'next_scheduled_at' => $scheduledAt,
                    'metadata' => ['source' => 'manual'],
                ],
            );

            return $this->firstMessage($campaign, $sequence, $scheduledAt, $content, hash('sha256', $idempotencyKey.'|1'));
        });

        if ($message->scheduled_at->lte(now())) {
            SendProactiveMessageJob::dispatch($message->id)->onQueue(config('proactive.queue', 'proactive'));
        }

        if ($message->wasRecentlyCreated) {
            $this->analytics->capture(
                $campaign->site,
                \App\Enums\AnalyticsEventType::PROACTIVE_MESSAGE_SCHEDULED,
                [
                    'visitor_id' => $message->visitor_id,
                    'conversation_id' => $message->conversation_id,
                    'agent_id' => $message->agent_id,
                    'workflow_id' => $message->workflow_id,
                    'source' => 'proactive',
                    'channel' => $message->channel,
                    'resource_type' => 'proactive_message',
                    'resource_id' => $message->id,
                ],
                ['campaign_id' => $message->campaign_id, 'sequence_id' => $message->sequence_id, 'step' => $message->step],
                $this->analytics->deterministicKey('proactive_message_scheduled', $message->id),
            );
        }

        return $message;
    }

    private function createFromTrigger(ProactiveTrigger $trigger, AnalyticsEvent $event): ?ProactiveMessage
    {
        $campaign = $trigger->campaign;
        if (!$campaign?->agent?->can_proactively_engage) return null;

        $socialId = $event->conversation_id
            ? SocialConversationLink::query()->where('conversation_id', $event->conversation_id)->value('social_conversation_id')
            : data_get($event->metadata, 'social_conversation_id');
        if (!$event->conversation_id && !$event->visitor_id && !$socialId) return null;

        // Le widget doit toujours reprendre un fil ELChat existant. Une
        // simple identité visiteur ne suffit pas à créer un message website;
        // cela évite de programmer un message qui ne pourrait jamais être
        // livré et évite toute création implicite de conversation.
        if ($campaign->channel === 'website' && !$event->conversation_id) return null;

        // Les canaux sociaux/email doivent cibler une conversation omnicanale
        // existante. Le lien peut venir de la conversation ELChat ou de la
        // métadonnée de l'événement provenant d'un connecteur.
        if ($campaign->channel !== 'website' && !$socialId) return null;

        $sequenceKey = hash('sha256', implode('|', ['event', $campaign->id, $trigger->id, $event->id]));
        $scheduledAt = $this->schedule->nextAllowedAt(
            $campaign,
            CarbonImmutable::parse($event->occurred_at ?? now())->addSeconds((int) $campaign->first_delay_seconds),
        );

        $message = DB::transaction(function () use ($campaign, $trigger, $event, $socialId, $sequenceKey, $scheduledAt) {
            $sequence = ProactiveSequence::query()->firstOrCreate(
                ['site_id' => $campaign->site_id, 'idempotency_key' => $sequenceKey],
                [
                    'account_id' => $campaign->account_id,
                    'campaign_id' => $campaign->id,
                    'conversation_id' => $event->conversation_id,
                    'visitor_id' => $event->visitor_id,
                    'social_conversation_id' => $socialId,
                    'channel' => $campaign->channel,
                    'status' => 'active',
                    'next_scheduled_at' => $scheduledAt,
                    'context_snapshot' => ['trigger_event_id' => $event->id, 'event_type' => $event->event_type],
                    'evidence' => ['analytics_event_id' => $event->id],
                    'metadata' => ['trigger_id' => $trigger->id, 'source' => 'analytics_event'],
                ],
            );

            $message = $this->firstMessage($campaign, $sequence, $scheduledAt, null, hash('sha256', $sequenceKey.'|1'));

            if ($sequence->wasRecentlyCreated) {
                $this->audit->record('sequence_started', [
                    'account_id' => $campaign->account_id, 'site_id' => $campaign->site_id,
                    'campaign_id' => $campaign->id, 'sequence_id' => $sequence->id, 'message_id' => $message->id,
                ], 'trigger_conditions_matched', metadata: ['trigger_id' => $trigger->id, 'analytics_event_id' => $event->id]);
                $this->analytics->capture(
                    $campaign->site,
                    \App\Enums\AnalyticsEventType::PROACTIVE_SEQUENCE_STARTED,
                    [
                        'visitor_id' => $sequence->visitor_id,
                        'conversation_id' => $sequence->conversation_id,
                        'agent_id' => $campaign->agent_id,
                        'workflow_id' => $campaign->workflow_id,
                        'source' => 'proactive',
                        'channel' => $sequence->channel,
                        'resource_type' => 'proactive_sequence',
                        'resource_id' => $sequence->id,
                        'causation_id' => $event->id,
                    ],
                    ['campaign_id' => $campaign->id, 'sequence_id' => $sequence->id, 'trigger_id' => $trigger->id],
                    $this->analytics->deterministicKey('proactive_sequence_started', $sequence->id),
                );
            }

            return $message;
        });

        if ($message->scheduled_at->lte(now())) {
            SendProactiveMessageJob::dispatch($message->id)->onQueue(config('proactive.queue', 'proactive'));
        }

        if ($message->wasRecentlyCreated) {
            $this->analytics->capture(
                $campaign->site,
                \App\Enums\AnalyticsEventType::PROACTIVE_MESSAGE_SCHEDULED,
                [
                    'visitor_id' => $message->visitor_id,
                    'conversation_id' => $message->conversation_id,
                    'agent_id' => $message->agent_id,
                    'workflow_id' => $message->workflow_id,
                    'source' => 'proactive',
                    'channel' => $message->channel,
                    'resource_type' => 'proactive_message',
                    'resource_id' => $message->id,
                    'causation_id' => $event->id,
                ],
                ['campaign_id' => $message->campaign_id, 'sequence_id' => $message->sequence_id, 'step' => $message->step],
                $this->analytics->deterministicKey('proactive_message_scheduled', $message->id),
            );
        }

        return $message;
    }

    private function firstMessage(ProactiveCampaign $campaign, ProactiveSequence $sequence, CarbonImmutable $scheduledAt, ?string $content, string $key): ProactiveMessage
    {
        return ProactiveMessage::query()->firstOrCreate(
            ['site_id' => $campaign->site_id, 'idempotency_key' => $key],
            [
                'account_id' => $campaign->account_id,
                'campaign_id' => $campaign->id,
                'sequence_id' => $sequence->id,
                'conversation_id' => $sequence->conversation_id,
                'visitor_id' => $sequence->visitor_id,
                'agent_id' => $campaign->agent_id,
                'workflow_id' => $campaign->workflow_id,
                'channel' => $campaign->channel,
                'status' => 'scheduled',
                'step' => 1,
                'content' => $content,
                'scheduled_at' => $scheduledAt,
                'metadata' => ['widget_behavior' => $campaign->widget_behavior, 'priority' => $campaign->priority],
            ],
        );
    }

    private function eventContext(AnalyticsEvent $event): array
    {
        return [
            'event' => [
                'id' => $event->id,
                'type' => $event->event_type,
                'value' => $event->value !== null ? (float) $event->value : null,
                'currency' => $event->currency,
                'source' => $event->source,
                'channel' => $event->channel,
                'resource_type' => $event->resource_type,
                'resource_id' => $event->resource_id,
                'metadata' => $event->metadata ?: [],
                'occurred_at' => $event->occurred_at?->toISOString(),
            ],
            'conversation' => $event->conversation?->only(['id', 'status', 'state', 'metadata']) ?? [],
            'visitor' => $event->visitor?->only(['id', 'device']) ?? [],
        ];
    }
}
