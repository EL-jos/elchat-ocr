<?php

namespace App\Services\VisitorIntelligence;

use App\Enums\AnalyticsEventType;
use App\Models\AnalyticsEvent;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\VisitorIntelligenceAction;
use App\Models\VisitorIntelligenceRule;
use App\Models\VisitorOpportunity;
use App\Models\Visitor;
use App\Models\VisitorSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class VisitorIntelligenceQueryService
{
    public function overview(Site $site, array $filters): array
    {
        [$from, $to] = $this->period($filters);
        $sessions = $this->sessionQuery($site, $from, $to, $filters);
        $total = (clone $sessions)->count();
        $withChat = (clone $sessions)->where('has_widget_interaction', true)->count();
        $convertedWithChat = (clone $sessions)->where('has_widget_interaction', true)->where('converted', true)->count();
        // Business events are fused to the filtered Visitor Intelligence
        // sessions; this keeps device/source/intent filters coherent.
        $events = $this->eventQuery($site, $from, $to, $filters)->whereIn('session_id', (clone $sessions)->select('session_key'));
        $metric = fn (string $key, string $label, ?int $value, string $unit = 'count', bool $available = true) => [
            'key' => $key, 'label' => $label, 'value' => $value, 'unit' => $unit, 'available' => $available,
        ];

        $kpis = [
            $metric('visitors', 'Visiteurs', $this->distinct($sessions, 'visitor_id'), 'count', $total > 0),
            $metric('sessions', 'Sessions', $total, 'count', $total > 0),
            $metric('new_visitors', 'Nouveaux visiteurs', (clone $sessions)->where('is_new_visitor', true)->distinct('visitor_id')->count('visitor_id'), 'count', $total > 0),
            $metric('returning_visitors', 'Visiteurs récurrents', (clone $sessions)->where('is_new_visitor', false)->distinct('visitor_id')->count('visitor_id'), 'count', $total > 0),
            $metric('sessions_with_elchat', 'Sessions avec ELChat', $withChat, 'count', $total > 0),
            $metric('sessions_without_elchat', 'Sessions sans ELChat', max(0, $total - $withChat), 'count', $total > 0),
            $metric('conversations', 'Conversations', $this->distinctEvent($events, 'conversation_id', [AnalyticsEventType::CONVERSATION_STARTED->value]), 'count', $total > 0),
            $metric('high_intent', 'Forte intention', (clone $sessions)->whereIn('intent_level', ['high'])->count(), 'count', $total > 0),
            $metric('leads', 'Leads', (clone $events)->where('event_type', AnalyticsEventType::LEAD_CREATED->value)->count(), 'count', $total > 0),
            $metric('appointments', 'Rendez-vous', (clone $events)->whereIn('event_type', [AnalyticsEventType::MEETING_BOOKED->value, AnalyticsEventType::APPOINTMENT_CREATED->value])->count(), 'count', $total > 0),
            $metric('conversions', 'Conversions', (clone $events)->whereIn('event_type', [AnalyticsEventType::CONVERSION->value, AnalyticsEventType::PURCHASE_COMPLETED->value])->count(), 'count', $total > 0),
            $metric('cta_impressions', 'CTA affichés', (clone $events)->whereIn('event_type', [AnalyticsEventType::CTA_IMPRESSION->value])->count(), 'count', $total > 0),
            $metric('cta_clicks', 'CTA cliqués', (clone $events)->whereIn('event_type', [AnalyticsEventType::CTA_CLICK->value])->count(), 'count', $total > 0),
            $metric('product_views', 'Produits consultés', (clone $events)->where('event_type', AnalyticsEventType::PRODUCT_VIEWED->value)->count(), 'count', $total > 0),
            $metric('page_views', 'Pages consultées', (clone $events)->where('event_type', AnalyticsEventType::PAGE_VIEW->value)->count(), 'count', $total > 0),
            $metric('document_views', 'Documents consultés', (clone $events)->whereIn('event_type', [AnalyticsEventType::DOCUMENT_DOWNLOADED->value, AnalyticsEventType::DOCUMENT_CLICKED->value])->count(), 'count', $total > 0),
            $metric('image_views', 'Images affichées', (clone $events)->whereIn('event_type', [AnalyticsEventType::IMAGE_DISPLAYED->value])->count(), 'count', $total > 0),
            $metric('engagement_rate', 'Taux d’engagement', $total > 0 ? (int) round($withChat / $total * 100, 1) : null, 'percent', $total > 0),
            $metric('widget_open_rate', 'Taux d’ouverture widget', $total > 0 ? (int) round($this->distinctEvent($events, 'session_id', [AnalyticsEventType::WIDGET_OPENED->value]) / $total * 100, 1) : null, 'percent', $total > 0),
            $metric('conversation_rate', 'Taux de conversation', $total > 0 ? (int) round($this->distinctEvent($events, 'session_id', [AnalyticsEventType::CONVERSATION_STARTED->value]) / $total * 100, 1) : null, 'percent', $total > 0),
            $metric('elchat_conversion_rate', 'Conversion associée à ELChat', $withChat > 0 ? (int) round($convertedWithChat / $withChat * 100, 1) : null, 'percent', $withChat > 0),
            $metric('abandoned_sessions', 'Abandons observés', (clone $sessions)->whereNotNull('ended_at')->where('converted', false)->count(), 'count', $total > 0),
            $metric('opportunities', 'Opportunités', VisitorOpportunity::query()->where('site_id', $site->id)->whereBetween('detected_at', [$from, $to])->count(), 'count', $total > 0),
            $metric('actions_executed', 'Actions exécutées', VisitorIntelligenceAction::query()->where('site_id', $site->id)->where('status', 'completed')->whereBetween('executed_at', [$from, $to])->count(), 'count', $total > 0),
        ];

        return [
            'period' => ['from' => $from->toISOString(), 'to' => $to->toISOString()],
            'kpis' => $kpis,
            'trend' => $this->trend($site, $from, $to, $filters),
            'anomalies' => $this->anomalies($site, $from, $to, $filters),
            'recommendations' => $this->recommendations($site, $sessions, $events),
            'data_quality' => ['has_observations' => $total > 0, 'note' => $total > 0 ? 'Les métriques sont limitées aux événements et sessions réellement observés.' : 'Aucune donnée fiable sur cette période.'],
        ];
    }

    public function sessions(Site $site, array $filters)
    {
        [$from, $to] = $this->period($filters);
        return $this->sessionQuery($site, $from, $to, $filters)
            ->with(['summary', 'visitor:id,uuid,device'])
            ->latest('started_at')
            ->paginate(min(100, max(10, (int) ($filters['per_page'] ?? 25))));
    }

    /**
     * Group the filtered sessions by the pseudonymous visitor identity. The
     * grouping never crosses the site boundary and exposes only aggregate
     * journey facts useful to an administrator.
     */
    public function visitors(Site $site, array $filters): array
    {
        [$from, $to] = $this->period($filters);
        $rows = $this->sessionQuery($site, $from, $to, $filters)
            ->whereNotNull('visitor_id')
            ->select([
                'visitor_id',
                DB::raw('COUNT(*) as sessions_count'),
                DB::raw('MIN(started_at) as first_seen_at'),
                DB::raw('MAX(last_seen_at) as last_seen_at'),
                DB::raw('SUM(page_count) as pages'),
                DB::raw('SUM(has_widget_interaction) as elchat_sessions'),
                DB::raw('SUM(converted) as conversions'),
                DB::raw("SUM(CASE WHEN intent_level = 'high' THEN 1 ELSE 0 END) as high_intent_sessions"),
            ])
            ->groupBy('visitor_id')
            ->orderByDesc('last_seen_at')
            ->limit(100)
            ->get();

        if ($rows->isEmpty()) return [];

        $visitors = Visitor::query()
            ->where('site_id', $site->id)
            ->whereIn('id', $rows->pluck('visitor_id'))
            ->get(['id', 'uuid', 'device'])
            ->keyBy('id');

        return $rows->map(function ($row) use ($visitors) {
            $visitor = $visitors->get($row->visitor_id);
            return [
                'visitor_id' => (string) $row->visitor_id,
                'visitor_uuid' => $visitor?->uuid,
                'device' => $visitor?->device,
                'sessions_count' => (int) $row->sessions_count,
                'first_seen_at' => $row->first_seen_at,
                'last_seen_at' => $row->last_seen_at,
                'pages' => (int) $row->pages,
                'elchat_sessions' => (int) $row->elchat_sessions,
                'conversions' => (int) $row->conversions,
                'high_intent_sessions' => (int) $row->high_intent_sessions,
            ];
        })->values()->all();
    }

    public function sessionDetail(Site $site, string $sessionId): array
    {
        $session = VisitorSession::query()->where('site_id', $site->id)->with(['summary', 'visitor'])->findOrFail($sessionId);
        $events = AnalyticsEvent::query()
            ->where('site_id', $site->id)
            ->where('session_id', $session->session_key)
            ->orderBy('occurred_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'event_type', 'resource_type', 'resource_id', 'label', 'metadata', 'conversation_id', 'occurred_at']);
        $conversationIds = $events->pluck('conversation_id')->filter()->unique()->values();
        $conversations = Conversation::query()
            ->where('site_id', $site->id)
            ->whereIn('id', $conversationIds)
            ->withCount('messages')
            ->get(['id', 'site_id', 'visitor_id', 'status', 'summary', 'created_at', 'updated_at']);
        $legacyFrameFiles = $this->legacyFrameFiles($site, $session);

        return [
            'session' => $session,
            'summary' => $session->summary,
            'timeline' => $events->map(function (AnalyticsEvent $event) use ($legacyFrameFiles): array {
                $metadata = $event->metadata ?? [];
                $label = $this->nullableReplayText($event->label);
                foreach (['path', 'title', 'target', 'reason', 'end_reason'] as $key) {
                    if (array_key_exists($key, $metadata)) $metadata[$key] = $this->nullableReplayText($metadata[$key]);
                }
                $screenshotPath = $metadata['screenshot_path'] ?? null;
                if (empty($screenshotPath) && empty($metadata['screenshot_url']) && $legacyFrameFiles) {
                    $screenshotPath = $this->legacyFramePathForEvent($metadata, $legacyFrameFiles);
                    if ($screenshotPath) $metadata['screenshot_path'] = $screenshotPath;
                }
                if (empty($metadata['screenshot_url']) && is_string($screenshotPath) && str_starts_with($screenshotPath, 'visitor-intelligence/frames/')) {
                    $metadata['screenshot_url'] = Storage::disk(
                        (string) config('visitor-intelligence.frame_storage_disk', 'public')
                    )->url($screenshotPath);
                }

                return [
                    'id' => $event->id,
                    'event_type' => $event->event_type,
                    'resource_type' => $event->resource_type,
                    'resource_id' => $event->resource_id,
                    'label' => $label,
                    'metadata' => $metadata,
                    'conversation_id' => $event->conversation_id,
                    'occurred_at' => $event->occurred_at?->toISOString(),
                ];
            })->values()->all(),
            'conversations' => $conversations,
        ];
    }

    private function nullableReplayText(mixed $value): ?string
    {
        if ($value === null || !is_scalar($value)) return null;
        $text = trim((string) $value);
        return $text === '' || preg_match('/^(null|undefined)$/i', $text) ? null : $text;
    }

    /**
     * Older frame events had their storage references removed by the privacy
     * filter because the timestamp in the filename looked like a phone number.
     * Keep replay working by indexing the files that already exist for this
     * session and matching them by frame_index (or file size as a fallback).
     */
    private function legacyFrameFiles(Site $site, VisitorSession $session): array
    {
        $disk = Storage::disk((string) config('visitor-intelligence.frame_storage_disk', 'public'));
        $directory = "visitor-intelligence/frames/{$site->id}/{$session->id}";

        try {
            $paths = $disk->files($directory);
        } catch (\Throwable) {
            return [];
        }

        $files = [];
        foreach ($paths as $path) {
            if (!preg_match('/\.(?:jpe?g|png|webp)$/i', $path)) continue;
            $timestamp = 0;
            if (preg_match('/\/(\d{10,})-[^\/]+$/', $path, $matches)) {
                $timestamp = (int) $matches[1];
            }
            $files[] = [
                'path' => $path,
                'timestamp' => $timestamp,
                'size' => null,
            ];
        }

        usort($files, static fn (array $left, array $right): int =>
            ($left['timestamp'] <=> $right['timestamp']) ?: strcmp($left['path'], $right['path'])
        );

        return array_values($files);
    }

    private function legacyFramePathForEvent(array $metadata, array $files): ?string
    {
        $frameIndex = $metadata['frame_index'] ?? null;
        if (is_numeric($frameIndex)) {
            $index = (int) $frameIndex;
            if ($index >= 0 && isset($files[$index]['path'])) return $files[$index]['path'];
        }

        $bytes = $metadata['screenshot_bytes'] ?? null;
        if (!is_numeric($bytes) || (int) $bytes <= 0) return null;
        foreach ($files as &$file) {
            try {
                $file['size'] ??= Storage::disk(
                    (string) config('visitor-intelligence.frame_storage_disk', 'public')
                )->size($file['path']);
            } catch (\Throwable) {
                continue;
            }
            if ((int) $file['size'] === (int) $bytes) return $file['path'];
        }

        return null;
    }

    public function journey(Site $site, array $filters): array
    {
        [$from, $to] = $this->period($filters);
        $sessionIds = $this->sessionQuery($site, $from, $to, $filters)->pluck('id');
        $sessionKeys = VisitorSession::query()->whereIn('id', $sessionIds)->pluck('session_key');
        $events = AnalyticsEvent::query()
            ->where('site_id', $site->id)
            ->whereIn('session_id', $sessionKeys)
            ->whereIn('event_type', [AnalyticsEventType::PAGE_VIEW->value, AnalyticsEventType::NAVIGATION->value])
            ->orderBy('occurred_at')
            ->get(['session_id', 'event_type', 'metadata']);
        $paths = $events->groupBy('session_id')->map(fn ($rows) => $rows->map(fn ($row) => data_get($row->metadata, 'path'))->filter()->unique()->take(12)->implode(' > '))->filter();
        $frequent = $paths->countBy()->sortDesc()->take(20)->map(fn ($count, $path) => ['path' => $path, 'sessions' => $count])->values()->all();
        $convertedSessions = VisitorSession::query()->whereIn('id', $sessionIds)->where('converted', true)->pluck('session_key');
        $conversionPaths = $paths->only($convertedSessions->all())->countBy()->sortDesc()->take(20)->map(fn ($count, $path) => ['path' => $path, 'sessions' => $count])->values()->all();
        $dropOff = VisitorSession::query()
            ->whereIn('id', $sessionIds)
            ->where('converted', false)
            ->whereNotNull('exit_url')
            ->select('exit_url', DB::raw('COUNT(*) as sessions'))
            ->groupBy('exit_url')
            ->orderByDesc('sessions')
            ->limit(20)
            ->get()
            ->map(fn ($row) => ['page' => $row->exit_url, 'sessions' => (int) $row->sessions])
            ->values()
            ->all();
        $journeyEvents = AnalyticsEvent::query()->where('site_id', $site->id)->whereIn('session_id', $sessionKeys);
        $funnel = [
            ['key' => 'sessions', 'label' => 'Sessions', 'sessions' => $sessionIds->count()],
            ['key' => 'elchat', 'label' => 'Avec ELChat', 'sessions' => (clone $this->sessionQuery($site, $from, $to, $filters))->where('has_widget_interaction', true)->count()],
            ['key' => 'conversations', 'label' => 'Conversations', 'sessions' => $this->distinctEvent((clone $journeyEvents), 'session_id', [AnalyticsEventType::CONVERSATION_STARTED->value])],
            ['key' => 'leads', 'label' => 'Leads', 'sessions' => $this->distinctEvent((clone $journeyEvents), 'session_id', [AnalyticsEventType::LEAD_CREATED->value])],
            ['key' => 'appointments', 'label' => 'Rendez-vous', 'sessions' => $this->distinctEvent((clone $journeyEvents), 'session_id', [AnalyticsEventType::MEETING_BOOKED->value, AnalyticsEventType::APPOINTMENT_CREATED->value])],
            ['key' => 'conversions', 'label' => 'Conversions', 'sessions' => $this->distinctEvent((clone $journeyEvents), 'session_id', [AnalyticsEventType::CONVERSION->value, AnalyticsEventType::PURCHASE_COMPLETED->value])],
        ];

        return [
            'frequent_paths' => $frequent, 'conversion_paths' => $conversionPaths, 'drop_off' => $dropOff, 'funnel' => $funnel,
            'segments' => [
                ['key' => 'with_elchat', 'label' => 'Avec ELChat', 'sessions' => (clone $this->sessionQuery($site, $from, $to, $filters))->where('has_widget_interaction', true)->count()],
                ['key' => 'without_elchat', 'label' => 'Sans ELChat', 'sessions' => (clone $this->sessionQuery($site, $from, $to, $filters))->where('has_widget_interaction', false)->count()],
                ['key' => 'converted', 'label' => 'Convertis', 'sessions' => (clone $this->sessionQuery($site, $from, $to, $filters))->where('converted', true)->count()],
            ],
        ];
    }

    public function opportunities(Site $site, array $filters)
    {
        [$from, $to] = $this->period($filters);
        return VisitorOpportunity::query()->where('site_id', $site->id)->whereBetween('detected_at', [$from, $to])->with(['session', 'actions'])->latest('detected_at')->paginate(min(100, max(10, (int) ($filters['per_page'] ?? 25))));
    }

    public function actions(Site $site, array $filters)
    {
        return VisitorIntelligenceAction::query()->where('site_id', $site->id)->with(['rule', 'opportunity'])->latest()->paginate(min(100, max(10, (int) ($filters['per_page'] ?? 25))));
    }

    public function rules(Site $site): array
    {
        return VisitorIntelligenceRule::query()->where('site_id', $site->id)->withCount('actions')->latest()->get()->all();
    }

    private function sessionQuery(Site $site, Carbon $from, Carbon $to, array $filters): Builder
    {
        // A replay is a finished journey, not a live activity feed. Keeping
        // active sessions out of this read model also prevents the dashboard
        // from exposing partial event timelines while the visitor is still on
        // the tenant site.
        return VisitorSession::query()->where('site_id', $site->id)->whereNotNull('ended_at')->whereBetween('started_at', [$from, $to])
            ->when($filters['device'] ?? null, fn ($q, $value) => $q->where('device', $value))
            ->when($filters['source'] ?? null, fn ($q, $value) => $q->where('source', $value))
            ->when($filters['intent'] ?? null, fn ($q, $value) => $q->where('intent_level', $value))
            ->when($filters['visitor_type'] ?? null, fn ($q, $value) => $q->where('is_new_visitor', $value === 'new'))
            ->when(array_key_exists('with_elchat', $filters) && $filters['with_elchat'] !== null, fn ($q) => $q->where('has_widget_interaction', (bool) $filters['with_elchat']))
            ->when(array_key_exists('converted', $filters) && $filters['converted'] !== null, fn ($q) => $q->where('converted', (bool) $filters['converted']))
            ->when($filters['visitor_id'] ?? null, fn ($q, $value) => $q->where('visitor_id', $value))
            ->when($filters['page'] ?? null, fn ($q, $value) => $q->where(function ($q) use ($value) { $q->where('entry_url', 'like', "%{$value}%")->orWhere('exit_url', 'like', "%{$value}%"); }));
    }

    private function eventQuery(Site $site, Carbon $from, Carbon $to, array $filters): Builder
    {
        // Visitor Intelligence is a projection of the shared analytics stream.
        // Keep chat, proactive, workflow and MCP events in the same timeline.
        return AnalyticsEvent::query()->where('site_id', $site->id)->whereBetween('occurred_at', [$from, $to])
            ->when($filters['source'] ?? null, fn ($q, $value) => $q->where(function ($q) use ($value) {
                // Acquisition source is stored on the session/browser metadata;
                // event source remains the producer (chat, widget, proactive…).
                $q->where('source', $value)->orWhereJsonContains('metadata->source', $value);
            }));
    }

    private function distinct(Builder $query, string $column): int
    {
        return (clone $query)->whereNotNull($column)->distinct($column)->count($column);
    }

    private function distinctEvent(Builder $query, string $column, array $types): int
    {
        return (clone $query)->whereIn('event_type', $types)->whereNotNull($column)->distinct($column)->count($column);
    }

    private function trend(Site $site, Carbon $from, Carbon $to, array $filters): array
    {
        return $this->sessionQuery($site, $from, $to, $filters)->select(DB::raw('DATE(started_at) as day'), DB::raw('COUNT(*) as sessions'), DB::raw('SUM(has_widget_interaction) as engaged'), DB::raw('SUM(converted) as conversions'))->groupBy(DB::raw('DATE(started_at)'))->orderBy('day')->get()->map(fn ($row) => ['date' => $row->day, 'sessions' => (int) $row->sessions, 'engaged' => (int) $row->engaged, 'conversions' => (int) $row->conversions])->all();
    }

    private function anomalies(Site $site, Carbon $from, Carbon $to, array $filters): array
    {
        $duration = max(86400, $from->diffInSeconds($to));
        $previousTo = $from->copy()->subSecond();
        $previousFrom = $previousTo->copy()->subSeconds($duration);
        $current = $this->sessionQuery($site, $from, $to, $filters);
        $previous = $this->sessionQuery($site, $previousFrom, $previousTo, $filters);
        $currentTotal = (clone $current)->count();
        $previousTotal = (clone $previous)->count();
        if ($currentTotal < 10 || $previousTotal < 10) return [];

        $currentAbandon = (clone $current)->whereNotNull('ended_at')->where('converted', false)->count() / $currentTotal;
        $previousAbandon = (clone $previous)->whereNotNull('ended_at')->where('converted', false)->count() / $previousTotal;
        if ($currentAbandon <= $previousAbandon * 1.25 || $currentAbandon - $previousAbandon < 0.10) return [];

        return [[
            'key' => 'abandonment_increase', 'severity' => 'medium',
            'label' => 'Hausse inhabituelle des abandons',
            'description' => 'Le taux d’abandon observé dépasse le précédent intervalle comparable avec un écart suffisamment documenté.',
            'evidence' => [
                'current_rate' => round($currentAbandon * 100, 2),
                'previous_rate' => round($previousAbandon * 100, 2),
                'current_sessions' => $currentTotal, 'previous_sessions' => $previousTotal,
            ],
        ]];
    }

    private function recommendations(Site $site, Builder $sessions, Builder $events): array
    {
        $recommendations = [];
        $highIntentAbandons = (clone $sessions)->where('intent_level', 'high')->whereNotNull('ended_at')->where('converted', false)->count();
        if ($highIntentAbandons > 0) {
            $recommendations[] = [
                'key' => 'review_high_intent_abandonment', 'label' => 'Examiner les abandons à forte intention',
                'description' => 'Comparer les timelines et conversations reliées avant d’activer une relance.',
                'evidence_count' => $highIntentAbandons,
            ];
        }
        $frictions = (clone $events)->whereIn('event_type', [AnalyticsEventType::UNANSWERED_QUESTION->value, AnalyticsEventType::LOW_CONFIDENCE_ANSWER->value])->count();
        if ($frictions > 0) {
            $recommendations[] = [
                'key' => 'review_friction', 'label' => 'Prioriser les frictions observées',
                'description' => 'Revoir les sources de connaissance liées aux questions sans réponse ou à faible confiance.',
                'evidence_count' => $frictions,
            ];
        }
        return $recommendations;
    }

    private function period(array $filters): array
    {
        $to = !empty($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : now()->endOfDay();
        $from = !empty($filters['from']) ? Carbon::parse($filters['from'])->startOfDay() : $to->copy()->subDays((int) config('analytics.default_period_days', 30))->startOfDay();
        if ($from->gt($to)) [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        $maxDays = max(1, (int) config('analytics.max_period_days', 366));
        if ($from->diffInDays($to) > $maxDays) {
            $from = $to->copy()->subDays($maxDays)->startOfDay();
        }
        return [$from, $to];
    }
}
