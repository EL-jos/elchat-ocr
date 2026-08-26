<?php

namespace App\Domain\AIEngagement;

use App\Domain\Proactive\ProactiveSequenceService;
use App\Enums\AnalyticsEventType;
use App\Models\AIEngagementDecision;
use App\Models\AnalyticsEvent;
use App\Models\Conversation;
use App\Models\Mcp\McpAgent;
use App\Models\Mcp\McpWorkflow;
use App\Models\Proactive\ProactiveCampaign;
use App\Models\Proactive\ProactiveMessage;
use App\Models\Site;
use App\Models\Visitor;
use App\Models\WidgetSetting;
use App\Services\analytics\AnalyticsEventService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

class AIEngagementService
{
    private const EVALUATION_EVENTS = [
        'page_view', 'navigation', 'page_exit', 'scroll_depth', 'click',
        'cta_impression', 'cta_click', 'product_viewed', 'product_clicked',
        'document_clicked', 'document_downloaded', 'commercial_intent_detected',
        'support_intent_detected', 'purchase_intent_detected', 'pricing_intent_detected',
        'booking_intent_detected', 'intent_detected',
        'unanswered_question', 'low_confidence_answer',
    ];

    public function __construct(
        private readonly AIEngagementContextBuilder $contextBuilder,
        private readonly AIEngagementScorer $scorer,
        private readonly AIEngagementMessageStrategy $messages,
        private readonly ProactiveSequenceService $sequences,
        private readonly AnalyticsEventService $analytics,
    ) {}

    public static function evaluationEvents(): array
    {
        return self::EVALUATION_EVENTS;
    }

    public function evaluate(AnalyticsEvent $event): ?AIEngagementDecision
    {
        if (!in_array($event->event_type, self::EVALUATION_EVENTS, true)) return null;
        if ($event->source === 'ai_engagement' || str_starts_with($event->event_type, 'engagement_')) return null;

        $site = $event->site ?: Site::query()->find($event->site_id);
        if (!$site) return null;
        $settings = WidgetSetting::query()->where('site_id', $site->id)->first();
        if (!$settings?->ai_engagement_enabled || !$settings->widget_enabled) return null;

        $context = $this->contextBuilder->build($event);
        $evaluation = $this->scorer->evaluate($context, $settings);
        $idempotencyKey = $this->analytics->deterministicKey('ai-engagement-evaluation', $site->id, (string) $event->id);

        try {
            $decision = AIEngagementDecision::query()->firstOrCreate(
                ['site_id' => $site->id, 'idempotency_key' => $idempotencyKey],
                [
                    'account_id' => $site->account_id,
                    'visitor_session_id' => $context['session']['id'] ?? null,
                    'visitor_id' => $event->visitor_id,
                    'source_event_id' => $event->id,
                    'decision' => $evaluation['decision'],
                    'score' => $evaluation['score'],
                    'intent_level' => $evaluation['intent_level'],
                    'page_type' => $evaluation['page_type'],
                    'reason' => $evaluation['reason'],
                    'signals' => $evaluation['signals'],
                    'context_snapshot' => $context,
                    'evaluated_at' => now(),
                ],
            );
        } catch (QueryException) {
            // Une livraison répétée ou deux workers concurrents peuvent arriver
            // sur la contrainte unique : l'état durable reste la réponse.
            return AIEngagementDecision::query()
                ->where('site_id', $site->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
        }

        if (!$decision->wasRecentlyCreated) return $decision;

        $this->capture($site, AnalyticsEventType::ENGAGEMENT_EVALUATED, $decision, [
            'decision' => $evaluation['decision'],
            'score' => $evaluation['score'],
            'intent_level' => $evaluation['intent_level'],
            'page_type' => $evaluation['page_type'],
            'reason' => $evaluation['reason'],
        ]);

        if ($evaluation['decision'] === 'engage_now') {
            $this->capture($site, AnalyticsEventType::ENGAGEMENT_CANDIDATE, $decision, [
                'score' => $evaluation['score'],
                'page_type' => $evaluation['page_type'],
            ]);
        }

        if ($evaluation['decision'] !== 'engage_now') {
            $this->capture($site, AnalyticsEventType::ENGAGEMENT_SKIPPED, $decision, [
                'decision' => $evaluation['decision'],
                'reason' => $evaluation['reason'],
            ]);
            return $decision;
        }

        $agent = $this->resolveAgent($site, $settings);
        if (!$agent) {
            return $this->decline($site, $decision, 'Aucun agent actif autorisé pour un engagement widget.');
        }

        try {
            $conversation = DB::transaction(function () use ($site, $event, $settings, $context, $decision): ?Conversation {
                $visitor = $event->visitor_id
                    ? Visitor::query()->where('site_id', $site->id)->lockForUpdate()->find($event->visitor_id)
                    : null;
                if (!$visitor) {
                    $decision->update([
                        'decision' => 'do_not_engage',
                        'reason' => 'Le visiteur n’est pas identifié pour cette session.',
                    ]);
                    return null;
                }

                $previousSessionEngagements = AIEngagementDecision::query()
                    ->where('site_id', $site->id)
                    ->where('visitor_session_id', $context['session']['id'] ?? null)
                    ->where('decision', 'engage_now')
                    ->where('id', '!=', $decision->id)
                    ->count();
                $visitorWindow = now()->subSeconds((int) $settings->ai_engagement_visitor_window_seconds);
                $previousVisitorEngagements = AIEngagementDecision::query()
                    ->where('site_id', $site->id)
                    ->where('visitor_id', $visitor->id)
                    ->where('decision', 'engage_now')
                    ->where('evaluated_at', '>=', $visitorWindow)
                    ->where('id', '!=', $decision->id)
                    ->count();

                if ($previousSessionEngagements >= (int) $settings->ai_engagement_max_per_session) {
                    $decision->update(['decision' => 'do_not_engage', 'reason' => 'Limite d’engagements par session atteinte.']);
                    return null;
                }
                if ($previousVisitorEngagements >= (int) $settings->ai_engagement_max_per_visitor) {
                    $decision->update(['decision' => 'do_not_engage', 'reason' => 'Limite d’engagements par visiteur atteinte.']);
                    return null;
                }

                $conversation = Conversation::query()
                    ->where('site_id', $site->id)
                    ->where('visitor_id', $visitor->id)
                    ->where('status', 'active')
                    ->latest('updated_at')
                    ->first();

                if (!$conversation) {
                    $conversation = Conversation::query()->create([
                        'site_id' => $site->id,
                        'visitor_id' => $visitor->id,
                        'metadata' => [
                            'channel' => 'widget',
                            'source' => 'ai_engagement',
                            'session_id' => $event->session_id,
                            'ai_engagement_decision_id' => $decision->id,
                        ],
                    ]);
                }

                $decision->update(['conversation_id' => $conversation->id]);
                return $conversation;
            });

            if (!$conversation || $decision->fresh()->decision !== 'engage_now') {
                $this->capture($site, AnalyticsEventType::ENGAGEMENT_SKIPPED, $decision->fresh(), [
                    'reason' => $decision->fresh()->reason,
                ]);
                return $decision->fresh();
            }

            $workflow = $this->resolveWorkflow($site, $settings);
            $campaign = $this->managedCampaign($site, $settings, $agent, $workflow);
            $messagePlan = $this->messages->create($context, $settings);
            $message = $this->sequences->scheduleAIEngagement(
                campaign: $campaign,
                conversation: $conversation,
                visitorId: $event->visitor_id,
                content: $messagePlan['message'],
                idempotencyKey: $this->analytics->deterministicKey('ai-engagement-message', $decision->id),
                metadata: [
                    'source' => 'ai_engagement',
                    'ai_engagement_decision_id' => $decision->id,
                    'page_type' => $evaluation['page_type'],
                    'strategy' => $messagePlan['strategy'],
                ],
            );

            $decision->update([
                'strategy' => data_get($message->metadata, 'strategy'),
                'proactive_message_id' => $message->id,
                'conversation_id' => $conversation->id,
            ]);
            $decision = $decision->fresh();
            $this->capture($site, AnalyticsEventType::ENGAGEMENT_TRIGGERED, $decision, [
                'proactive_message_id' => $message->id,
                'conversation_id' => $conversation->id,
                'strategy' => $decision->strategy,
            ]);

            return $decision;
        } catch (Throwable $exception) {
            return $this->decline($site, $decision, 'Le déclenchement n’a pas pu être préparé.', $exception->getMessage());
        }
    }

    private function decline(Site $site, AIEngagementDecision $decision, string $reason, ?string $details = null): AIEngagementDecision
    {
        $decision->update(['decision' => 'do_not_engage', 'reason' => $reason, 'signals' => [
            ...($decision->signals ?: []),
            'failure' => $details ? mb_substr($details, 0, 500) : $reason,
        ]]);
        $this->capture($site, AnalyticsEventType::ENGAGEMENT_SKIPPED, $decision->fresh(), ['reason' => $reason]);
        return $decision->fresh();
    }

    private function resolveAgent(Site $site, WidgetSetting $settings): ?McpAgent
    {
        $query = McpAgent::query()
            ->where('site_id', $site->id)
            ->where('is_active', true)
            ->where('can_proactively_engage', true);

        if ($settings->ai_engagement_agent_id) {
            $selected = (clone $query)->whereKey($settings->ai_engagement_agent_id)->first();
            if ($selected) return $selected;
        }

        return $query->orderByDesc('is_default')->orderBy('created_at')->first();
    }

    private function resolveWorkflow(Site $site, WidgetSetting $settings): ?McpWorkflow
    {
        if (!$settings->ai_engagement_workflow_id) return null;
        return McpWorkflow::query()
            ->where('site_id', $site->id)
            ->whereKey($settings->ai_engagement_workflow_id)
            ->where('is_active', true)
            ->first();
    }

    private function managedCampaign(Site $site, WidgetSetting $settings, McpAgent $agent, ?McpWorkflow $workflow): ProactiveCampaign
    {
        return DB::transaction(function () use ($site, $settings, $agent, $workflow): ProactiveCampaign {
            $widgetSettings = WidgetSetting::query()->whereKey($settings->id)->lockForUpdate()->first();
            $campaign = ProactiveCampaign::query()->where('site_id', $site->id)->get()
                ->first(fn (ProactiveCampaign $candidate) => data_get($candidate->metadata, 'source') === 'ai_engagement');

            $attributes = [
                'account_id' => $site->account_id,
                'site_id' => $site->id,
                'agent_id' => $agent->id,
                'workflow_id' => $workflow?->id,
                'name' => 'AI Engagement',
                'description' => 'Campagne gérée par le moteur AI Engagement.',
                'status' => 'active',
                'channel' => 'website',
                'decision_mode' => 'template',
                'widget_behavior' => $widgetSettings?->ai_engagement_widget_behavior ?: 'auto_open',
                'priority' => 10,
                'timezone' => config('app.timezone', 'UTC'),
                'allowed_days' => [1, 2, 3, 4, 5, 6, 7],
                'first_delay_seconds' => 0,
                'follow_up_intervals' => [],
                'max_messages' => 1,
                'cooldown_seconds' => (int) ($widgetSettings?->ai_engagement_cooldown_seconds ?? 86400),
                'site_daily_cap' => 500,
                'visitor_daily_cap' => (int) ($widgetSettings?->ai_engagement_max_per_visitor ?? 2),
                'conversation_total_cap' => 1,
                'stop_on_reply' => true,
                'stop_on_conversion' => true,
                'stop_on_human_handoff' => true,
                'stop_on_refusal' => true,
                'stop_on_unsubscribe' => true,
                'metadata' => [
                    'source' => 'ai_engagement',
                    'managed' => true,
                    'approved_at' => now()->toISOString(),
                ],
            ];

            if (!$campaign) return ProactiveCampaign::query()->create($attributes);
            $campaign->update($attributes);
            return $campaign->fresh();
        });
    }

    private function capture(Site $site, AnalyticsEventType $type, AIEngagementDecision $decision, array $metadata = []): void
    {
        $this->analytics->capture(
            $site,
            $type,
            [
                'visitor_id' => $decision->visitor_id,
                'conversation_id' => $decision->conversation_id,
                'source' => 'ai_engagement',
                'channel' => 'website',
                'resource_type' => 'ai_engagement_decision',
                'resource_id' => $decision->id,
                'causation_id' => $decision->source_event_id,
            ],
            $metadata,
            $this->analytics->deterministicKey('ai-engagement-event', $type->value, $decision->id),
        );
    }
}
