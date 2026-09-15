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
                'channel' => $event->channel ?: data_get($event->metadata, 'channel'),
            ],
            'session' => $session->toArray(),
            'visitor' => $session->visitor?->toArray() ?? [],
        ];

        foreach ($rules as $rule) {
            if (!$this->conditions->evaluate($rule->conditions ?: [], $context, data_get($rule->action, 'condition_mode', 'all'))) continue;
            if (!$this->matchesChannel($rule->channel, $event, $context)) continue;
            if (!$this->matchesAudience($rule->audience, $context)) continue;
            if (!$this->matchesSchedule($rule->schedule, $event->occurred_at?->copy() ?? now())) continue;
            if ($this->isPolicyBlocked($rule, $event, $session)) continue;

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

            if ($action->wasRecentlyCreated && !$action->approval_required) {
                ExecuteVisitorIntelligenceActionJob::dispatch($action->id);
            }
            if ($action->wasRecentlyCreated) {
                // Kept as an informational timestamp for the admin UI. Policy
                // decisions use action history scoped to the visitor/session,
                // never this global rule timestamp.
                $rule->update(['last_triggered_at' => now()]);
            }
            $this->realtime->publish((string) $event->site_id, 'action_created', [
                'action_id' => (string) $action->id,
                'session_id' => (string) $session->id,
                'rule_id' => (string) $rule->id,
                'status' => (string) $action->status,
            ]);
        }
    }

    private function matchesChannel(?string $ruleChannel, AnalyticsEvent $event, array $context): bool
    {
        $ruleChannel = strtolower(trim((string) $ruleChannel));
        if ($ruleChannel === '') return true;

        $eventChannel = strtolower(trim((string) (
            $event->channel
            ?: data_get($event->metadata, 'channel')
            ?: data_get($context, 'session.metadata.channel')
            ?: ''
        )));

        return $eventChannel !== '' && $eventChannel === $ruleChannel;
    }

    private function matchesAudience(?array $audience, array $context): bool
    {
        if (!$audience) return true;

        $mode = (string) ($audience['condition_mode'] ?? 'all');
        $conditions = $audience['conditions'] ?? $audience;
        if (!is_array($conditions)) return true;

        if (!array_is_list($conditions)) {
            $conditions = collect($conditions)
                ->reject(fn ($value, $field) => in_array((string) $field, ['condition_mode', 'conditions'], true))
                ->map(function ($value, $field) use ($context): array {
                    $field = (string) $field;
                    if (!str_contains($field, '.')) {
                        $field = array_key_exists($field, $context['visitor'] ?? [])
                            ? 'visitor.'.$field
                            : 'session.'.$field;
                    }

                    return [
                        'field' => $field,
                        'operator' => is_array($value) ? 'in' : 'eq',
                        'value' => $value,
                    ];
                })
                ->values()
                ->all();
        }

        return $this->conditions->evaluate($conditions, $context, $mode);
    }

    private function matchesSchedule(?array $schedule, Carbon $occurredAt): bool
    {
        if (!$schedule) return true;

        $at = $occurredAt->copy();
        if (!empty($schedule['timezone'])) {
            try {
                $at->setTimezone((string) $schedule['timezone']);
            } catch (\Throwable) {
                return false;
            }
        }

        foreach (['start_at' => 'gte', 'end_at' => 'lte'] as $key => $operator) {
            if (empty($schedule[$key])) continue;
            try {
                $boundary = Carbon::parse((string) $schedule[$key], $at->getTimezone());
            } catch (\Throwable) {
                return false;
            }
            if ($operator === 'gte' && $at->lt($boundary)) return false;
            if ($operator === 'lte' && $at->gt($boundary)) return false;
        }

        $days = $schedule['days'] ?? $schedule['days_of_week'] ?? null;
        if (is_string($days)) $days = preg_split('/\s*,\s*/', $days, -1, PREG_SPLIT_NO_EMPTY);
        if (is_array($days) && $days !== [] && !$this->matchesScheduleDay($at, $days)) return false;

        $startTime = $schedule['start_time'] ?? $schedule['from'] ?? $schedule['time_start'] ?? null;
        $endTime = $schedule['end_time'] ?? $schedule['to'] ?? $schedule['time_end'] ?? null;
        $allowedHours = $schedule['allowed_hours'] ?? null;
        if (!$startTime && is_array($allowedHours) && isset($allowedHours[0])) $startTime = $allowedHours[0];
        if (!$endTime && is_array($allowedHours) && isset($allowedHours[1])) $endTime = $allowedHours[1];
        if ($startTime || $endTime) {
            $current = ((int) $at->format('H')) * 60 + (int) $at->format('i');
            $start = $startTime ? $this->minutesFromTime($startTime) : 0;
            $end = $endTime ? $this->minutesFromTime($endTime) : 1439;
            if ($start === null || $end === null) return false;
            $within = $start <= $end
                ? $current >= $start && $current <= $end
                : $current >= $start || $current <= $end;
            if (!$within) return false;
        }

        return true;
    }

    private function matchesScheduleDay(Carbon $at, array $days): bool
    {
        $names = [
            0 => ['0', 'sun', 'sunday', 'dim', 'dimanche'],
            1 => ['1', 'mon', 'monday', 'lun', 'lundi'],
            2 => ['2', 'tue', 'tuesday', 'mar', 'mardi'],
            3 => ['3', 'wed', 'wednesday', 'mer', 'mercredi'],
            4 => ['4', 'thu', 'thursday', 'jeu', 'jeudi'],
            5 => ['5', 'fri', 'friday', 'ven', 'vendredi'],
            6 => ['6', 'sat', 'saturday', 'sam', 'samedi'],
        ];
        $current = $at->dayOfWeek;
        return collect($days)->contains(function ($day) use ($current, $names): bool {
            $value = strtolower(trim((string) $day));
            return in_array($value, $names[$current], true);
        });
    }

    private function minutesFromTime(mixed $value): ?int
    {
        if (!is_string($value) && !is_numeric($value)) return null;
        if (is_numeric($value)) return max(0, min(1439, (int) $value));
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($value), $matches)) return null;
        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];
        if ($hours > 23 || $minutes > 59) return null;
        return $hours * 60 + $minutes;
    }

    private function isPolicyBlocked(VisitorIntelligenceRule $rule, AnalyticsEvent $event, VisitorSession $session): bool
    {
        $actions = VisitorIntelligenceAction::query()
            ->where('site_id', $event->site_id)
            ->where('rule_id', $rule->id);
        $visitorActions = $this->actionsForVisitor($actions, $session);
        $limits = is_array($rule->limits) ? $rule->limits : [];

        $maxTotal = $this->limit($limits, ['max_total', 'max_actions']);
        if ($maxTotal !== null && (clone $actions)->count() >= $maxTotal) return true;

        $maxPerSession = $this->limit($limits, ['max_per_session', 'max_actions_per_session']);
        if ($maxPerSession !== null && (clone $actions)->where('visitor_session_id', $session->id)->count() >= $maxPerSession) return true;

        $maxPerVisitor = $this->limit($limits, ['max_per_visitor', 'max_actions_per_visitor']);
        if ($maxPerVisitor !== null && (clone $visitorActions)->count() >= $maxPerVisitor) return true;

        $maxPerDay = $this->limit($limits, ['max_per_day', 'max_actions_per_day', 'daily']);
        if ($maxPerDay !== null) {
            $today = now();
            if ((clone $actions)->whereBetween('created_at', [$today->copy()->startOfDay(), $today->copy()->endOfDay()])->count() >= $maxPerDay) return true;
        }

        $frequency = strtolower((string) ($rule->frequency ?: 'event'));
        if ($frequency === 'session' && (clone $actions)->where('visitor_session_id', $session->id)->exists()) return true;
        if ($frequency === 'once' && (clone $visitorActions)->exists()) return true;
        if ($frequency === 'day') {
            $today = now();
            if ((clone $visitorActions)->whereBetween('created_at', [$today->copy()->startOfDay(), $today->copy()->endOfDay()])->exists()) return true;
        }

        $cooldown = max(0, (int) $rule->cooldown_seconds);
        if ($cooldown > 0) {
            $lastAction = (clone $visitorActions)->latest('created_at')->first();
            if ($lastAction?->created_at && abs(now()->diffInSeconds($lastAction->created_at)) < $cooldown) return true;
        }

        return false;
    }

    private function actionsForVisitor($actions, VisitorSession $session)
    {
        if ($session->visitor_id) {
            return $actions->whereHas('session', fn ($query) => $query->where('visitor_id', $session->visitor_id));
        }

        return $actions->where('visitor_session_id', $session->id);
    }

    private function limit(array $limits, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $limits) && is_numeric($limits[$key])) return max(0, (int) $limits[$key]);
        }
        return null;
    }
}
