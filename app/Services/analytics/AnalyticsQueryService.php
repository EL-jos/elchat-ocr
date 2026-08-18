<?php

namespace App\Services\analytics;

use App\Enums\AnalyticsEventType;
use App\Models\AnalyticsEvent;
use App\Models\Site;
use App\Models\UnansweredQuestion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AnalyticsQueryService
{
    public function __construct(private readonly AnalyticsEventService $events)
    {
    }

    public function overview(Site $site, array $filters): array
    {
        $period = $this->period($filters);
        $current = $this->eventSummary($site, $period['from'], $period['to'], $filters);
        $previous = $this->eventSummary($site, $period['previous_from'], $period['previous_to'], $filters);

        $conversations = $this->count($current, AnalyticsEventType::CONVERSATION_STARTED);
        $previousConversations = $this->count($previous, AnalyticsEventType::CONVERSATION_STARTED);
        $aiResolved = $this->aiResolvedCount($site, $period['from'], $period['to'], $filters);
        $previousAiResolved = $this->aiResolvedCount($site, $period['previous_from'], $period['previous_to'], $filters);
        $handoffs = $this->count($current, AnalyticsEventType::HUMAN_HANDOFF);
        $previousHandoffs = $this->count($previous, AnalyticsEventType::HUMAN_HANDOFF);
        $ctaImpressions = $this->count($current, AnalyticsEventType::CTA_IMPRESSION);
        $previousCtaImpressions = $this->count($previous, AnalyticsEventType::CTA_IMPRESSION);
        $ctaClicks = $this->count($current, AnalyticsEventType::CTA_CLICK);
        $previousCtaClicks = $this->count($previous, AnalyticsEventType::CTA_CLICK);
        $agentStarted = $this->count($current, AnalyticsEventType::AGENT_STARTED);
        $previousAgentStarted = $this->count($previous, AnalyticsEventType::AGENT_STARTED);
        $workflowStarted = $this->count($current, AnalyticsEventType::WORKFLOW_STARTED);
        $previousWorkflowStarted = $this->count($previous, AnalyticsEventType::WORKFLOW_STARTED);
        $mcpStarted = $this->count($current, AnalyticsEventType::MCP_ACTION_STARTED);
        $previousMcpStarted = $this->count($previous, AnalyticsEventType::MCP_ACTION_STARTED);

        $revenue = $this->attributedRevenue($site, $period['from'], $period['to'], $filters);
        $previousRevenue = $this->attributedRevenue($site, $period['previous_from'], $period['previous_to'], $filters);

        $kpis = [
            $this->kpi('conversations', 'Conversations assistées', $conversations, $previousConversations),
            $this->kpi('visitors_assisted', 'Visiteurs assistés', $this->uniqueVisitors($current), $this->uniqueVisitors($previous)),
            $this->kpi('conversations_resolved', 'Conversations résolues', $this->count($current, AnalyticsEventType::CONVERSATION_RESOLVED), $this->count($previous, AnalyticsEventType::CONVERSATION_RESOLVED)),
            $this->kpi('commercial_intents', 'Intentions commerciales', $this->count($current, AnalyticsEventType::COMMERCIAL_INTENT_DETECTED), $this->count($previous, AnalyticsEventType::COMMERCIAL_INTENT_DETECTED)),
            $this->kpi('leads_generated', 'Leads générés', $this->count($current, AnalyticsEventType::LEAD_CREATED), $this->count($previous, AnalyticsEventType::LEAD_CREATED)),
            $this->kpi('opportunities_created', 'Opportunités créées', $this->count($current, AnalyticsEventType::OPPORTUNITY_CREATED), $this->count($previous, AnalyticsEventType::OPPORTUNITY_CREATED)),
            $this->kpi('meetings_booked', 'Rendez-vous pris', $this->count($current, AnalyticsEventType::MEETING_BOOKED), $this->count($previous, AnalyticsEventType::MEETING_BOOKED)),
            $this->kpi('cta_impressions', 'CTA affichés', $ctaImpressions, $previousCtaImpressions),
            $this->kpi('cta_clicks', 'CTA cliqués', $ctaClicks, $previousCtaClicks),
            $this->kpi('products_recommended', 'Produits recommandés', $this->count($current, AnalyticsEventType::PRODUCT_RECOMMENDED), $this->count($previous, AnalyticsEventType::PRODUCT_RECOMMENDED)),
            $this->kpi('products_clicked', 'Produits cliqués', $this->count($current, AnalyticsEventType::PRODUCT_CLICKED), $this->count($previous, AnalyticsEventType::PRODUCT_CLICKED)),
            $this->kpi('products_added_to_cart', 'Produits ajoutés au panier', $this->count($current, AnalyticsEventType::PRODUCT_ADDED_TO_CART), $this->count($previous, AnalyticsEventType::PRODUCT_ADDED_TO_CART)),
            $this->kpi('purchases', 'Achats observés', $this->count($current, AnalyticsEventType::PURCHASE_COMPLETED), $this->count($previous, AnalyticsEventType::PURCHASE_COMPLETED)),
            $this->kpi('revenue_attributed', 'Revenu attribué', $revenue['value'], $previousRevenue['value'], 'currency', $revenue['available']),
            $this->kpi('ai_resolution_rate', 'Taux de résolution IA', $this->rate($aiResolved, $conversations), $this->rate($previousAiResolved, $previousConversations), 'percent'),
            $this->kpi('human_handoff_rate', 'Taux de transfert humain', $this->rate($handoffs, $conversations), $this->rate($previousHandoffs, $previousConversations), 'percent'),
            $this->kpi('cta_ctr', 'Taux de clic CTA', $this->rate($ctaClicks, $ctaImpressions), $this->rate($previousCtaClicks, $previousCtaImpressions), 'percent'),
            $this->kpi('workflows_executed', 'Workflows exécutés', $workflowStarted, $previousWorkflowStarted),
            $this->kpi('agent_success_rate', 'Succès des agents', $this->rate($this->count($current, AnalyticsEventType::AGENT_COMPLETED), $agentStarted), $this->rate($this->count($previous, AnalyticsEventType::AGENT_COMPLETED), $previousAgentStarted), 'percent'),
            $this->kpi('agents_executed', 'Agents exécutés', $agentStarted, $previousAgentStarted),
            $this->kpi('workflow_success_rate', 'Succès des workflows', $this->rate($this->count($current, AnalyticsEventType::WORKFLOW_COMPLETED), $workflowStarted), $this->rate($this->count($previous, AnalyticsEventType::WORKFLOW_COMPLETED), $previousWorkflowStarted), 'percent'),
            $this->kpi('mcp_actions', 'Actions MCP', $mcpStarted, $previousMcpStarted),
            $this->kpi('mcp_success_rate', 'Succès des actions MCP', $this->rate($this->count($current, AnalyticsEventType::MCP_ACTION_COMPLETED), $mcpStarted), $this->rate($this->count($previous, AnalyticsEventType::MCP_ACTION_COMPLETED), $previousMcpStarted), 'percent'),
            $this->kpi('unanswered_questions', 'Questions sans réponse', $this->count($current, AnalyticsEventType::UNANSWERED_QUESTION), $this->count($previous, AnalyticsEventType::UNANSWERED_QUESTION)),
            $this->kpi('low_confidence_answers', 'Réponses à faible confiance', $this->count($current, AnalyticsEventType::LOW_CONFIDENCE_ANSWER), $this->count($previous, AnalyticsEventType::LOW_CONFIDENCE_ANSWER)),
        ];

        return [
            'period' => $this->serializePeriod($period),
            'kpis' => $kpis,
            'trend' => $this->businessTrend($site, $period['from'], $period['to'], $filters),
            'data_quality' => [
                'revenue_available' => $revenue['available'],
                'revenue_currency' => $revenue['currency'],
                'attribution_note' => $revenue['available']
                    ? 'Somme limitée aux événements avec valeur monétaire et attribution directe ou assistée.'
                    : 'Aucun montant attribuable observé sur cette période.',
            ],
        ];
    }

    public function businessImpact(Site $site, array $filters): array
    {
        $period = $this->period($filters);
        $types = [
            AnalyticsEventType::LEAD_CREATED,
            AnalyticsEventType::CONTACT_CREATED,
            AnalyticsEventType::OPPORTUNITY_CREATED,
            AnalyticsEventType::OPPORTUNITY_WON,
            AnalyticsEventType::MEETING_BOOKED,
            AnalyticsEventType::PURCHASE_COMPLETED,
        ];

        $rows = $this->baseQuery($site, $period['from'], $period['to'], $filters)
            ->whereIn('event_type', array_map(fn ($type) => $type->value, $types))
            ->select('event_type', 'source', 'attribution_type', DB::raw('COUNT(*) as total'), DB::raw('SUM(value) as value_sum'))
            ->groupBy('event_type', 'source', 'attribution_type')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'event_type' => $row->event_type,
                'source' => $row->source,
                'attribution_type' => $row->attribution_type,
                'count' => (int) $row->total,
                'value' => $row->value_sum !== null ? round((float) $row->value_sum, 2) : null,
            ])
            ->values();

        return ['period' => $this->serializePeriod($period), 'outcomes' => $rows];
    }

    public function funnel(Site $site, array $filters, ?array $requestedSteps = null): array
    {
        $period = $this->period($filters);
        $steps = $requestedSteps ?: [
            AnalyticsEventType::WIDGET_OPENED->value,
            AnalyticsEventType::CONVERSATION_STARTED->value,
            AnalyticsEventType::INTENT_DETECTED->value,
            AnalyticsEventType::CTA_IMPRESSION->value,
            AnalyticsEventType::CTA_CLICK->value,
            AnalyticsEventType::LEAD_CREATED->value,
            AnalyticsEventType::OPPORTUNITY_CREATED->value,
            AnalyticsEventType::MEETING_BOOKED->value,
            AnalyticsEventType::PURCHASE_COMPLETED->value,
        ];

        $currentVolumes = $this->sequentialFunnelVolumes($site, $period['from'], $period['to'], $steps, $filters);
        $previousVolumes = $this->sequentialFunnelVolumes($site, $period['previous_from'], $period['previous_to'], $steps, $filters);
        $previousStepVolume = null;

        $data = collect($steps)->map(function (string $eventType, int $index) use ($currentVolumes, $previousVolumes, &$previousStepVolume) {
            $volume = $currentVolumes[$index] ?? 0;
            $previous = $previousVolumes[$index] ?? 0;
            $conversion = $index === 0 ? 100.0 : $this->rate($volume, $previousStepVolume ?? 0);
            $previousStepVolume = $volume;

            return [
                'event_type' => $eventType,
                'label' => $this->labelFor($eventType),
                'volume' => $volume,
                'conversion_rate' => $conversion,
                'previous_volume' => $previous,
                'change_percent' => $this->change($volume, $previous),
            ];
        })->all();

        return [
            'period' => $this->serializePeriod($period),
            'method' => 'observed_sequential_correlation',
            'steps' => $data,
        ];
    }

    public function knowledge(Site $site, array $filters): array
    {
        $period = $this->period($filters);
        $rows = UnansweredQuestion::query()
            ->where('site_id', $site->id)
            ->whereBetween('created_at', [$period['from'], $period['to']])
            ->select(
                DB::raw('LOWER(TRIM(question)) as normalized_question'),
                DB::raw('MAX(question) as question'),
                DB::raw('COUNT(*) as occurrences'),
                DB::raw('MAX(created_at) as last_seen_at'),
            )
            ->groupBy(DB::raw('LOWER(TRIM(question))'))
            ->orderByDesc('occurrences')
            ->limit(50)
            ->get()
            ->map(function ($row) {
                $occurrences = (int) $row->occurrences;
                $priority = $occurrences >= 10 ? 'high' : ($occurrences >= 3 ? 'medium' : 'low');

                return [
                    'question' => $this->redactKnowledgeQuestion($row->question),
                    'occurrences' => $occurrences,
                    'last_seen_at' => $row->last_seen_at,
                    'priority' => $priority,
                    'source_missing' => null,
                    'confidence' => null,
                    'potential_impact' => $priority,
                    'evidence' => "Question restée sans réponse {$occurrences} fois sur la période.",
                    'recommendation' => 'Ajouter ou actualiser une source de connaissance qui répond explicitement à cette question.',
                ];
            })
            ->values();

        return [
            'period' => $this->serializePeriod($period),
            'questions' => $rows,
            'data_quality' => [
                'confidence_available' => false,
                'source_gap_available' => false,
                'note' => 'La confiance et la source manquante restent non disponibles tant qu’elles ne sont pas observées explicitement.',
            ],
        ];
    }

    public function executionPerformance(Site $site, array $filters, string $kind): array
    {
        $period = $this->period($filters);
        [$idColumn, $started, $completed, $failed] = match ($kind) {
            'agents' => ['agent_id', AnalyticsEventType::AGENT_STARTED, AnalyticsEventType::AGENT_COMPLETED, AnalyticsEventType::AGENT_FAILED],
            'workflows' => ['workflow_id', AnalyticsEventType::WORKFLOW_STARTED, AnalyticsEventType::WORKFLOW_COMPLETED, AnalyticsEventType::WORKFLOW_FAILED],
            default => ['resource_id', AnalyticsEventType::MCP_ACTION_STARTED, AnalyticsEventType::MCP_ACTION_COMPLETED, AnalyticsEventType::MCP_ACTION_FAILED],
        };

        $query = $this->baseQuery($site, $period['from'], $period['to'], $filters)
            ->whereIn('event_type', [$started->value, $completed->value, $failed->value]);

        $query->whereNotNull($idColumn)->select($idColumn);
        $rows = $query
            ->addSelect(
                DB::raw("SUM(CASE WHEN event_type = '{$started->value}' THEN 1 ELSE 0 END) as started"),
                DB::raw("SUM(CASE WHEN event_type = '{$completed->value}' THEN 1 ELSE 0 END) as completed"),
                DB::raw("SUM(CASE WHEN event_type = '{$failed->value}' THEN 1 ELSE 0 END) as failed"),
            )
            ->groupBy($idColumn)
            ->get()
            ->map(function ($row) use ($idColumn) {
                $startedCount = (int) $row->started;

                return [
                    'id' => $row->{$idColumn},
                    'started' => $startedCount,
                    'completed' => (int) $row->completed,
                    'failed' => (int) $row->failed,
                    'success_rate' => $this->rate((int) $row->completed, $startedCount),
                ];
            })
            ->values();

        return ['period' => $this->serializePeriod($period), 'data' => $rows];
    }

    public function recommendations(Site $site, array $filters): array
    {
        $period = $this->period($filters);
        $minimum = max(3, (int) config('analytics.insight_minimum_sample', 10));
        $failureThreshold = (float) config('analytics.execution_failure_rate_threshold', 0.15);
        $recommendations = [];

        foreach ($this->knowledge($site, $filters)['questions']->take(5) as $question) {
            if ($question['occurrences'] < $minimum) {
                continue;
            }

            $recommendations[] = [
                'id' => hash('sha256', 'knowledge:'.$question['question']),
                'category' => 'knowledge',
                'problem' => 'Une question fréquente reste sans réponse fiable.',
                'evidence' => $question['evidence'],
                'impact' => 'Ces visiteurs peuvent abandonner ou demander une intervention humaine.',
                'priority' => $question['priority'],
                'recommended_action' => $question['recommendation'],
                'observed_data' => ['occurrences' => $question['occurrences']],
            ];
        }

        foreach ($this->ctaPerformance($site, $period, $filters) as $cta) {
            if ($cta['impressions'] < $minimum || !$cta['below_site_average']) {
                continue;
            }

            $recommendations[] = [
                'id' => hash('sha256', 'cta:'.$cta['id']),
                'category' => 'cta',
                'problem' => "Le CTA « {$cta['label']} » est moins cliqué que la moyenne du site.",
                'evidence' => "{$cta['clicks']} clic(s) pour {$cta['impressions']} affichage(s), soit {$cta['ctr']} % contre {$cta['site_ctr']} % sur le site.",
                'impact' => 'Une partie des intentions observées ne progresse pas vers l’action proposée.',
                'priority' => $cta['ctr'] === 0.0 ? 'high' : 'medium',
                'recommended_action' => 'Tester un libellé ou un emplacement plus explicite, puis comparer le CTR sur une période équivalente.',
                'observed_data' => $cta,
            ];
        }

        foreach (['agents', 'workflows', 'mcp'] as $kind) {
            foreach ($this->executionPerformance($site, $filters, $kind)['data'] as $execution) {
                if ($execution['started'] < $minimum) {
                    continue;
                }

                $failureRate = $execution['started'] > 0 ? $execution['failed'] / $execution['started'] : 0;
                if ($failureRate < $failureThreshold) {
                    continue;
                }

                $label = match ($kind) {
                    'agents' => 'agent',
                    'workflows' => 'workflow',
                    default => 'connecteur MCP',
                };
                $recommendations[] = [
                    'id' => hash('sha256', "{$kind}:{$execution['id']}"),
                    'category' => $kind,
                    'problem' => "Le {$label} {$execution['id']} dépasse le seuil d’échec configuré.",
                    'evidence' => "{$execution['failed']} échec(s) sur {$execution['started']} exécution(s), soit ".round($failureRate * 100, 1).' %.',
                    'impact' => 'Les résultats métier dépendant de cette exécution peuvent ne pas être produits.',
                    'priority' => $failureRate >= 0.30 ? 'high' : 'medium',
                    'recommended_action' => 'Examiner les erreurs récentes et les permissions du connecteur avant de relancer les exécutions en échec.',
                    'observed_data' => $execution,
                ];
            }
        }

        $priority = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($recommendations, fn (array $left, array $right) => ($priority[$left['priority']] ?? 3) <=> ($priority[$right['priority']] ?? 3));

        return [
            'period' => $this->serializePeriod($period),
            'method' => 'deterministic_observed_rules',
            'recommendations' => array_slice($recommendations, 0, 25),
        ];
    }

    public function anomalies(Site $site, array $filters): array
    {
        $period = $this->period($filters);
        $current = $this->eventSummary($site, $period['from'], $period['to'], $filters);
        $previous = $this->eventSummary($site, $period['previous_from'], $period['previous_to'], $filters);
        $minimum = max(3, (int) config('analytics.insight_minimum_sample', 10));
        $threshold = max(0.05, (float) config('analytics.anomaly_relative_threshold', 0.25));
        $anomalies = [];

        $comparisons = [
            ['conversation_volume', 'Volume des conversations', $this->count($current, AnalyticsEventType::CONVERSATION_STARTED), $this->count($previous, AnalyticsEventType::CONVERSATION_STARTED), 'either', 'Vérifier les changements de trafic, de disponibilité du widget ou de campagne.'],
            ['lead_drop', 'Leads générés', $this->count($current, AnalyticsEventType::LEAD_CREATED), $this->count($previous, AnalyticsEventType::LEAD_CREATED), 'down', 'Examiner le funnel intention → CTA → lead pour localiser la rupture.'],
            ['mcp_failures', 'Échecs MCP', $this->count($current, AnalyticsEventType::MCP_ACTION_FAILED), $this->count($previous, AnalyticsEventType::MCP_ACTION_FAILED), 'up', 'Contrôler les connecteurs, permissions et erreurs MCP les plus fréquentes.'],
            ['human_handoffs', 'Transferts humains', $this->count($current, AnalyticsEventType::HUMAN_HANDOFF), $this->count($previous, AnalyticsEventType::HUMAN_HANDOFF), 'up', 'Comparer les motifs de transfert et les lacunes de connaissance observées.'],
            ['unanswered_questions', 'Questions sans réponse', $this->count($current, AnalyticsEventType::UNANSWERED_QUESTION), $this->count($previous, AnalyticsEventType::UNANSWERED_QUESTION), 'up', 'Prioriser les questions récurrentes dans la base de connaissances.'],
        ];

        foreach ($comparisons as [$key, $label, $currentValue, $previousValue, $direction, $action]) {
            $anomaly = $this->buildAnomaly($key, $label, $currentValue, $previousValue, $direction, $action, $minimum, $threshold);
            if ($anomaly) {
                $anomalies[] = $anomaly;
            }
        }

        $currentCtaImpressions = $this->count($current, AnalyticsEventType::CTA_IMPRESSION);
        $previousCtaImpressions = $this->count($previous, AnalyticsEventType::CTA_IMPRESSION);
        if (max($currentCtaImpressions, $previousCtaImpressions) >= $minimum) {
            $anomaly = $this->buildAnomaly(
                'cta_ctr',
                'Taux de clic CTA',
                $this->rate($this->count($current, AnalyticsEventType::CTA_CLICK), $currentCtaImpressions),
                $this->rate($this->count($previous, AnalyticsEventType::CTA_CLICK), $previousCtaImpressions),
                'down',
                'Identifier les CTA dont le CTR a le plus baissé et tester une variante mesurable.',
                0,
                $threshold,
                'percent'
            );
            if ($anomaly) {
                $anomalies[] = $anomaly;
            }
        }

        return [
            'period' => $this->serializePeriod($period),
            'method' => 'previous_period_relative_change',
            'threshold_percent' => round($threshold * 100, 1),
            'anomalies' => $anomalies,
        ];
    }

    public function period(array $filters): array
    {
        $to = isset($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : now()->endOfDay();
        $from = isset($filters['from'])
            ? Carbon::parse($filters['from'])->startOfDay()
            : $to->copy()->subDays(config('analytics.default_period_days', 30) - 1)->startOfDay();

        if ($from->greaterThan($to)) {
            throw ValidationException::withMessages(['from' => 'La date de début doit précéder la date de fin.']);
        }

        if ($from->diffInDays($to) + 1 > config('analytics.max_period_days', 366)) {
            throw ValidationException::withMessages(['from' => 'La période demandée est trop longue.']);
        }

        $seconds = $from->diffInSeconds($to) + 1;
        $previousTo = $from->copy()->subSecond();

        return [
            'from' => $from,
            'to' => $to,
            'previous_from' => $previousTo->copy()->subSeconds($seconds - 1),
            'previous_to' => $previousTo,
        ];
    }

    private function eventSummary(Site $site, Carbon $from, Carbon $to, array $filters): array
    {
        $summary = [];
        $rows = $this->baseQuery($site, $from, $to, $filters)
            ->select(
                'event_type',
                'resource_type',
                DB::raw('COUNT(*) as total'),
                DB::raw('COUNT(DISTINCT visitor_id) as unique_visitors'),
                DB::raw('COUNT(DISTINCT conversation_id) as unique_conversations'),
                DB::raw('SUM(value) as value_sum'),
            )
            ->groupBy('event_type', 'resource_type')
            ->get();

        foreach ($rows as $row) {
            $eventType = $this->canonicalType($row->event_type, $row->resource_type);
            $summary[$eventType] ??= ['count' => 0, 'unique_visitors' => 0, 'unique_conversations' => 0, 'value_sum' => 0.0];
            $summary[$eventType]['count'] += (int) $row->total;
            $summary[$eventType]['unique_visitors'] += (int) $row->unique_visitors;
            $summary[$eventType]['unique_conversations'] += (int) $row->unique_conversations;
            $summary[$eventType]['value_sum'] += (float) ($row->value_sum ?? 0);
        }

        return $summary;
    }

    private function baseQuery(Site $site, Carbon $from, Carbon $to, array $filters): Builder
    {
        return AnalyticsEvent::query()
            ->forSite($site)
            ->occurredBetween($from, $to)
            ->when($filters['channel'] ?? null, fn (Builder $query, $channel) => $query->where('channel', $channel))
            ->when($filters['source'] ?? null, fn (Builder $query, $source) => $query->where('source', $source))
            ->when($filters['agent_id'] ?? null, fn (Builder $query, $agentId) => $query->where('agent_id', $agentId))
            ->when($filters['workflow_id'] ?? null, fn (Builder $query, $workflowId) => $query->where('workflow_id', $workflowId))
            ->when($filters['event_type'] ?? null, fn (Builder $query, $eventType) => $query->where('event_type', $eventType));
    }

    private function ctaPerformance(Site $site, array $period, array $filters): array
    {
        $rows = $this->baseQuery($site, $period['from'], $period['to'], $filters)
            ->where('resource_type', 'cta')
            ->whereNotNull('resource_id')
            ->whereIn('event_type', ['impression', 'click', AnalyticsEventType::CTA_IMPRESSION->value, AnalyticsEventType::CTA_CLICK->value])
            ->select(
                'resource_id',
                DB::raw('MAX(label) as label'),
                DB::raw("SUM(CASE WHEN event_type IN ('impression', 'cta_impression') THEN 1 ELSE 0 END) as impressions"),
                DB::raw("SUM(CASE WHEN event_type IN ('click', 'cta_click') THEN 1 ELSE 0 END) as clicks"),
            )
            ->groupBy('resource_id')
            ->get();

        $siteImpressions = (int) $rows->sum('impressions');
        $siteClicks = (int) $rows->sum('clicks');
        $siteCtr = $this->rate($siteClicks, $siteImpressions);

        return $rows->map(function ($row) use ($siteCtr) {
            $impressions = (int) $row->impressions;
            $clicks = (int) $row->clicks;
            $ctr = $this->rate($clicks, $impressions);

            return [
                'id' => $row->resource_id,
                'label' => $row->label ?: 'Sans libellé',
                'impressions' => $impressions,
                'clicks' => $clicks,
                'ctr' => $ctr,
                'site_ctr' => $siteCtr,
                'below_site_average' => $siteCtr > 0 && $ctr <= $siteCtr * 0.75,
            ];
        })->all();
    }

    private function buildAnomaly(
        string $key,
        string $label,
        int|float $current,
        int|float $previous,
        string $direction,
        string $recommendedAction,
        int $minimum,
        float $threshold,
        string $unit = 'count'
    ): ?array {
        if (max($current, $previous) < $minimum || ($current == 0 && $previous == 0)) {
            return null;
        }

        $relative = $previous == 0 ? null : ($current - $previous) / abs($previous);
        $directionMatches = match ($direction) {
            'up' => $current > $previous,
            'down' => $current < $previous,
            default => $current !== $previous,
        };
        $thresholdReached = $relative === null ? $current >= $minimum : abs($relative) >= $threshold;
        if (!$directionMatches || !$thresholdReached) {
            return null;
        }

        $changePercent = $relative === null ? null : round($relative * 100, 1);
        $severity = $relative === null || abs($relative) >= max(0.5, $threshold * 2) ? 'high' : 'medium';
        $movement = $current > $previous ? 'augmenté' : 'diminué';

        return [
            'id' => hash('sha256', "{$key}:{$current}:{$previous}"),
            'metric' => $key,
            'label' => $label,
            'severity' => $severity,
            'current_value' => $current,
            'previous_value' => $previous,
            'change_percent' => $changePercent,
            'unit' => $unit,
            'evidence' => "{$label} a {$movement}, de {$previous} à {$current} sur deux périodes comparables.",
            'interpretation' => 'La variation dépasse le seuil configuré et nécessite une vérification; elle ne prouve pas à elle seule une causalité.',
            'recommended_action' => $recommendedAction,
        ];
    }

    private function businessTrend(Site $site, Carbon $from, Carbon $to, array $filters): array
    {
        $types = [
            AnalyticsEventType::LEAD_CREATED->value,
            AnalyticsEventType::OPPORTUNITY_CREATED->value,
            AnalyticsEventType::MEETING_BOOKED->value,
            AnalyticsEventType::PURCHASE_COMPLETED->value,
        ];

        $completedDates = DB::table('analytics_daily_aggregate_runs')
            ->where('site_id', $site->id)
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            // Keep the current day real-time; hourly aggregates are intentionally
            // used only after the calendar day has closed.
            ->where('metric_date', '<', now()->toDateString())
            ->pluck('metric_date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->all();

        $rawRows = $this->baseQuery($site, $from, $to, $filters)
            ->whereIn('event_type', $types)
            ->when($completedDates, fn (Builder $query) => $query->whereNotIn(DB::raw('DATE(occurred_at)'), $completedDates))
            ->select(DB::raw('DATE(occurred_at) as day'), 'event_type', DB::raw('COUNT(*) as total'))
            ->groupBy(DB::raw('DATE(occurred_at)'), 'event_type')
            ->get();

        $aggregateRows = collect();
        if ($completedDates) {
            $aggregateRows = DB::table('analytics_daily_metrics')
                ->where('site_id', $site->id)
                ->whereIn('metric_date', $completedDates)
                ->whereIn('event_type', $types)
                ->when($filters['channel'] ?? null, fn ($query, $channel) => $query->where('channel', $channel))
                ->when($filters['source'] ?? null, fn ($query, $source) => $query->where('source', $source))
                ->when($filters['agent_id'] ?? null, fn ($query, $agentId) => $query->where('agent_id', $agentId))
                ->when($filters['workflow_id'] ?? null, fn ($query, $workflowId) => $query->where('workflow_id', $workflowId))
                ->when($filters['event_type'] ?? null, fn ($query, $eventType) => $query->where('event_type', $eventType))
                ->select('metric_date as day', 'event_type', DB::raw('SUM(event_count) as total'))
                ->groupBy('metric_date', 'event_type')
                ->get();
        }

        return $rawRows
            ->concat($aggregateRows)
            ->groupBy('day')
            ->map(fn (Collection $rows, string $day) => [
                'date' => $day,
                ...collect($types)->mapWithKeys(fn ($type) => [$type => (int) ($rows->firstWhere('event_type', $type)?->total ?? 0)])->all(),
            ])
            ->sortBy('date')
            ->values()
            ->all();
    }

    private function attributedRevenue(Site $site, Carbon $from, Carbon $to, array $filters): array
    {
        $query = $this->baseQuery($site, $from, $to, $filters)
            ->where('event_type', AnalyticsEventType::PURCHASE_COMPLETED->value)
            ->whereIn('attribution_type', ['direct', 'assisted'])
            ->whereNotNull('value');

        $count = (clone $query)->count();
        $currencies = (clone $query)->distinct()->pluck('currency')->filter()->values();

        return [
            'available' => $count > 0 && $currencies->count() === 1,
            'value' => $count > 0 && $currencies->count() === 1 ? round((float) $query->sum('value'), 2) : null,
            'currency' => $currencies->count() === 1 ? $currencies->first() : null,
        ];
    }

    private function aiResolvedCount(Site $site, Carbon $from, Carbon $to, array $filters): int
    {
        return $this->baseQuery($site, $from, $to, $filters)
            ->where('event_type', AnalyticsEventType::CONVERSATION_RESOLVED->value)
            ->whereIn('source', ['mcp', 'agent_orchestrator', 'elchat_platform'])
            ->distinct('conversation_id')
            ->count('conversation_id');
    }

    private function sequentialFunnelVolumes(Site $site, Carbon $from, Carbon $to, array $steps, array $filters): array
    {
        $volumes = [];

        foreach ($steps as $targetIndex => $eventType) {
            $query = DB::table('resource_events as e0')
                ->where('e0.site_id', $site->id)
                ->where('e0.event_type', $steps[0])
                ->whereBetween('e0.occurred_at', [$from, $to])
                ->whereNotNull('e0.correlation_id');

            $this->applyFunnelFilters($query, 'e0', $filters);

            for ($index = 1; $index <= $targetIndex; $index++) {
                $alias = "e{$index}";
                $previousAlias = 'e' . ($index - 1);
                $query->join("resource_events as {$alias}", function ($join) use ($alias, $previousAlias, $steps, $index, $site, $from, $to) {
                    $join->on("{$alias}.site_id", '=', "{$previousAlias}.site_id")
                        ->on("{$alias}.correlation_id", '=', "{$previousAlias}.correlation_id")
                        ->where("{$alias}.site_id", $site->id)
                        ->where("{$alias}.event_type", $steps[$index])
                        ->whereBetween("{$alias}.occurred_at", [$from, $to])
                        ->whereColumn("{$alias}.occurred_at", '>=', "{$previousAlias}.occurred_at");
                });
                $this->applyFunnelFilters($query, $alias, $filters);
            }

            $volumes[] = (int) $query->distinct()->count("e{$targetIndex}.correlation_id");
        }

        return $volumes;
    }

    private function applyFunnelFilters($query, string $alias, array $filters): void
    {
        foreach (['channel', 'source', 'agent_id', 'workflow_id'] as $filter) {
            if (!empty($filters[$filter])) {
                $query->where("{$alias}.{$filter}", $filters[$filter]);
            }
        }
    }

    private function canonicalType(string $eventType, ?string $resourceType): string
    {
        if (in_array($eventType, ['impression', 'click', 'conversion'], true) && $resourceType) {
            try {
                return $this->events->canonicalResourceEventType($resourceType, $eventType)->value;
            } catch (\InvalidArgumentException) {
                return $eventType;
            }
        }

        return $eventType;
    }

    private function count(array $summary, AnalyticsEventType $eventType): int
    {
        return (int) ($summary[$eventType->value]['count'] ?? 0);
    }

    private function uniqueVisitors(array $summary): int
    {
        $started = $summary[AnalyticsEventType::CONVERSATION_STARTED->value] ?? [];
        return (int) ($started['unique_visitors'] ?? 0);
    }

    private function kpi(string $key, string $label, int|float|null $value, int|float|null $previous, string $unit = 'count', bool $available = true): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'previous_value' => $previous,
            'change_percent' => $value !== null && $previous !== null ? $this->change($value, $previous) : null,
            'unit' => $unit,
            'available' => $available,
        ];
    }

    private function rate(int|float $numerator, int|float $denominator): float
    {
        return $denominator > 0 ? round(($numerator / $denominator) * 100, 1) : 0.0;
    }

    private function change(int|float $current, int|float $previous): ?float
    {
        if ($previous == 0) {
            return $current == 0 ? 0.0 : null;
        }

        return round((($current - $previous) / abs($previous)) * 100, 1);
    }

    private function serializePeriod(array $period): array
    {
        return collect($period)->map(fn (Carbon $date) => $date->toISOString())->all();
    }

    private function labelFor(string $eventType): string
    {
        return match ($eventType) {
            'widget_opened' => 'Widget ouvert',
            'conversation_started' => 'Conversation démarrée',
            'intent_detected' => 'Intention détectée',
            'cta_impression' => 'CTA affiché',
            'cta_click' => 'CTA cliqué',
            'lead_created' => 'Lead créé',
            'opportunity_created' => 'Opportunité créée',
            'meeting_booked' => 'Rendez-vous pris',
            'purchase_completed' => 'Achat observé',
            default => str($eventType)->replace('_', ' ')->ucfirst()->toString(),
        };
    }

    private function redactKnowledgeQuestion(string $question): string
    {
        $question = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '[email]', $question) ?? $question;
        $question = preg_replace('/(?:\+?\d[\s().\-]*){8,}/u', '[téléphone]', $question) ?? $question;
        return preg_replace('~https?://[^\s]+~iu', '[lien]', $question) ?? $question;
    }
}
