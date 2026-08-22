<?php

namespace App\Services\VisitorIntelligence;

use App\Jobs\VisitorIntelligence\ExecuteVisitorIntelligenceActionJob;
use App\Models\AnalyticsEvent;
use App\Models\VisitorIntelligenceAction;
use App\Models\VisitorIntelligenceRule;
use App\Models\VisitorSession;
use App\Domain\Proactive\ProactiveConditionEvaluator;
use Illuminate\Support\Carbon;

class VisitorIntelligenceRuleService
{
    public function __construct(
        private readonly ProactiveConditionEvaluator $conditions,
        private readonly VisitorIntelligenceRealtimeService $realtime,
    )
    {
    }

    public function evaluate(AnalyticsEvent $event, VisitorSession $session): void
    {
        $rules = VisitorIntelligenceRule::query()
            ->where('site_id', $event->site_id)
            ->where('is_active', true)
            ->whereIn('trigger', [$event->event_type, 'any_event'])
            ->get();

        $context = [
            'event' => [
                'id' => $event->id, 'type' => $event->event_type, 'value' => $event->value !== null ? (float) $event->value : null,
                'metadata' => $event->metadata ?? [], 'occurred_at' => $event->occurred_at?->toISOString(),
            ],
            'session' => $session->toArray(),
        ];

        foreach ($rules as $rule) {
            if (!$this->conditions->evaluate($rule->conditions ?: [], $context, data_get($rule->action, 'condition_mode', 'all'))) continue;
            if ($rule->last_triggered_at && now()->diffInSeconds($rule->last_triggered_at) < (int) $rule->cooldown_seconds) continue;

            $actionType = (string) data_get($rule->action, 'type', 'create_opportunity');
            $key = hash('sha256', implode('|', ['visitor-intelligence', $rule->id, $event->id, $actionType]));
            $action = VisitorIntelligenceAction::query()->firstOrCreate(
                ['site_id' => $event->site_id, 'idempotency_key' => $key],
                [
                    'account_id' => $event->account_id,
                    'visitor_session_id' => $session->id,
                    'rule_id' => $rule->id,
                    'action_type' => $actionType,
                    'source' => (string) data_get($rule->action, 'source', 'visitor_intelligence'),
                    'status' => $rule->approval_required ? 'pending' : 'queued',
                    'approval_required' => $rule->approval_required,
                    'payload' => [...($rule->action ?: []), 'evidence_event_id' => $event->id],
                ],
            );
            $rule->update(['last_triggered_at' => now()]);

            if ($action->wasRecentlyCreated && !$action->approval_required) {
                ExecuteVisitorIntelligenceActionJob::dispatch($action->id);
            }
            $this->realtime->publish((string) $event->site_id, 'action_created', [
                'action_id' => (string) $action->id,
                'session_id' => (string) $session->id,
                'rule_id' => (string) $rule->id,
                'status' => (string) $action->status,
            ]);
        }
    }
}
