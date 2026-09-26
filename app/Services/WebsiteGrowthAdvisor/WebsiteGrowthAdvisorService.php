<?php

namespace App\Services\WebsiteGrowthAdvisor;

use App\Domain\MCP\Security\ActorContext;
use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Exceptions\AuthExpiredException;
use App\Domain\MCP\Exceptions\ConnectorUnavailableException;
use App\Domain\MCP\Exceptions\PermissionDeniedException;
use App\Models\Conversation;
use App\Domain\RAG\RAGToolAdapter;
use App\Models\AnalyticsEvent;
use App\Models\Message;
use App\Models\Site;
use App\Models\VisitorSession;
use App\Models\WidgetSetting;
use App\Models\WebsiteGrowthAdvisorAnalysis;
use App\Services\WebsiteGrowthAdvisor\WebsiteGrowthAdvisorConfigurationService;
use App\Services\WebsiteGrowthAdvisor\WebsiteGrowthAdvisorPlanner;
use App\Services\VisitorIntelligence\VisitorIntelligenceQueryService;
use App\Services\VisitorIntelligence\VisitorIntelligenceSessionEvidenceService;
use App\Services\hops\LLMService;
use App\Services\mcp\MCPActionGateService;
use App\Services\vision\TargetedReplayVisualInspectionTool;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Orchestrates the Website Growth Advisor pipeline.
 *
 * The collection phase is deterministic and bounded. Raw replay chunks are
 * never sent to the LLM: only selected semantic events and, when available,
 * a few locally rendered visual moments are included as evidence.
 */
final class WebsiteGrowthAdvisorService
{
    private const MAX_CANDIDATE_SESSIONS = 250;
    private const MAX_REPRESENTATIVE_SESSIONS = 12;
    private const MAX_VISUAL_SESSIONS = 3;
    private const MAX_VISUAL_MOMENTS_PER_SESSION = 4;

    public function __construct(
        private readonly VisitorIntelligenceQueryService $visitorIntelligence,
        private readonly VisitorIntelligenceSessionEvidenceService $sessionEvidence,
        private readonly MCPActionGateService $mcp,
        private readonly WebsiteGrowthAdvisorConversationCleanupService $conversationCleanup,
        private readonly RAGToolAdapter $rag,
        private readonly LLMService $llm,
        private readonly WebsiteGrowthAdvisorConfigurationService $configurationService,
        private readonly WebsiteGrowthAdvisorPlanner $planner,
        private readonly WebsiteGrowthAdvisorLiveCrawler $liveCrawler,
        private readonly TargetedReplayVisualInspectionTool $replayVision,
    ) {
    }

    /** @return array<string, mixed> */
    public function collectSnapshot(
        Site $site,
        array $filters = [],
        bool $includeExternal = true,
        ?callable $progress = null,
        ?array $configuration = null,
        bool $includeVisualCaptures = false,
    ): array
    {
        $configuration = array_replace($this->configurationService->defaults(), $configuration ?? []);
        $plan = $this->planner->plan($configuration);
        [$from, $to] = $this->period($filters);
        $periodFilters = ['from' => $from->toDateString(), 'to' => $to->toDateString()];
        $evidence = [];
        $sourceStatus = [];
        $widgetSetting = WidgetSetting::query()
            ->where('site_id', $site->id)
            ->first();

        $widget = $this->widgetContext($widgetSetting);
        $sourceStatus['widget'] = [
            'status' => $widget['status'],
            'installed' => $widget['installed'],
            'enabled' => $widget['enabled'],
            'configured' => $widget['configured'],
        ];
        $this->registerEvidence(
            $evidence,
            'widget:settings',
            'widget_settings',
            'Configuration du widget du tenant',
            $widget,
        );
        $this->progress($progress, 15, 'collection', 'Vérification du widget et préparation des sources.');

        $visitorIntelligenceEnabled = (bool) ($plan['sources']['visitor_intelligence'] ?? false);
        $overview = $visitorIntelligenceEnabled
            ? $this->visitorIntelligence->overview($site, array_merge($periodFilters, ['visitor_segments' => $plan['visitor_segments']]))
            : ['kpis' => [], 'trend' => [], 'acquisition' => [], 'anomalies' => [], 'recommendations' => [], 'data_quality' => ['status' => 'not_requested']];
        $journey = $visitorIntelligenceEnabled && (bool) ($plan['journey_options']['analyze_journeys'] ?? true)
            ? $this->visitorIntelligence->journey($site, array_merge($periodFilters, ['visitor_segments' => $plan['visitor_segments']]))
            : ['frequent_paths' => [], 'conversion_paths' => [], 'drop_off' => [], 'funnel' => [], 'segments' => []];
        $comparison = [];
        if ($visitorIntelligenceEnabled && ($plan['period_comparison'] ?? false)) {
            $duration = max(1, $from->diffInDays($to));
            $previousTo = $from->copy()->subDay();
            $previousFrom = $previousTo->copy()->subDays($duration);
            $previousFilters = [
                'from' => $previousFrom->toDateString(),
                'to' => $previousTo->toDateString(),
                'visitor_segments' => $plan['visitor_segments'],
            ];
            $comparison = [
                'period' => ['from' => $previousFrom->toISOString(), 'to' => $previousTo->toISOString()],
                'overview' => $this->visitorIntelligence->overview($site, $previousFilters),
                'journey' => ($plan['journey_options']['analyze_journeys'] ?? true)
                    ? $this->visitorIntelligence->journey($site, $previousFilters)
                    : [],
            ];
        }
        if ($visitorIntelligenceEnabled) {
            $this->registerEvidence($evidence, 'visitor_intelligence:overview', 'visitor_intelligence', 'Vue d’ensemble Visitor Intelligence', [
                'period' => [$from->toISOString(), $to->toISOString()],
                'segments' => $plan['visitor_segments'],
            ]);
        }
        $this->progress($progress, 25, 'visitor_intelligence', 'Lecture des parcours et des indicateurs Visitor Intelligence.');

        $sessions = $visitorIntelligenceEnabled ? VisitorSession::query()
            ->where('site_id', $site->id)
            // A visual investigation must use a completed journey. Active
            // sessions may still be missing their final rrweb flush and would
            // create false "no rendered capture" results.
            ->whereNotNull('ended_at')
            ->whereBetween('started_at', [$from, $to])
            ->with('summary')
            ->withCount('replayChunks')
            ->when($plan['visitor_segments'] !== [], fn ($query) => $query->where(function ($segmentQuery) use ($plan): void {
                foreach ($plan['visitor_segments'] as $segment) {
                    match ($segment) {
                        'new' => $segmentQuery->orWhere('is_new_visitor', true),
                        'returning' => $segmentQuery->orWhere('is_new_visitor', false),
                        'organic', 'paid', 'direct', 'referral' => $segmentQuery->orWhere('acquisition_source_type', $segment),
                        default => null,
                    };
                }
            }))
            ->orderByDesc('converted')
            ->orderByDesc('has_widget_interaction')
            ->orderByDesc('page_count')
            ->limit((int) $plan['candidate_sessions_limit'])
            ->get() : collect();

        $representative = $this->selectRepresentativeSessions($sessions, (int) $plan['representative_sessions_limit']);
        if (($plan['visual']['enabled'] ?? false) && (int) ($plan['visual']['sessions_limit'] ?? 0) > 0) {
            // Keep the business-ranked journeys, then backfill a small bounded
            // set of completed journeys that actually contain replay chunks.
            // This is the visual-investigation pool; it prevents an otherwise
            // valid analysis from selecting only non-replayable sessions.
            $visualBackfill = $sessions
                ->filter(fn (VisitorSession $session): bool => (int) ($session->replay_chunks_count ?? 0) > 0)
                ->reject(fn (VisitorSession $session): bool => $representative->contains('id', $session->id))
                ->take((int) $plan['visual']['sessions_limit'])
                ->values();
            $representative = $representative
                ->concat($visualBackfill)
                ->unique(fn (VisitorSession $session): string => (string) $session->id)
                ->values();
        }
        $sessionKeys = $representative->pluck('session_key')->filter()->values();
        $acquisitionJourneyPatterns = $visitorIntelligenceEnabled
            ? $this->acquisitionJourneyPatterns($sessions, $evidence)
            : [];
        $eventRows = $sessionKeys->isEmpty()
            ? collect()
            : AnalyticsEvent::query()
                ->where('site_id', $site->id)
                ->whereIn('session_id', $sessionKeys)
                ->orderBy('occurred_at')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get([
                    'id', 'event_type', 'resource_type', 'resource_id', 'label', 'metadata',
                    'conversation_id', 'session_id', 'occurred_at',
                ]);
        $this->progress($progress, 32, 'collection', 'Sélection des parcours représentatifs et de leurs événements.');

        $conversationIds = ($plan['include_conversations'] ?? false)
            ? $eventRows->pluck('conversation_id')->filter()->unique()->values()
            : collect();
        $conversations = $conversationIds->isEmpty()
            ? collect()
            : Conversation::query()
                ->where('site_id', $site->id)
                ->whereIn('id', $conversationIds)
                ->get(['id', 'summary', 'status', 'created_at', 'updated_at'])
                ->keyBy(fn (Conversation $conversation): string => (string) $conversation->id);

        $messagesByConversation = $this->messagesByConversation((string) $site->id, $conversationIds);
        $sessionAnalyses = [];
        $visualCandidateCount = 0;
        foreach ($representative as $session) {
            $sessionEvents = $eventRows->where('session_id', $session->session_key)->values();
            $moments = $visitorIntelligenceEnabled
                ? $this->annotateMoments(
                    $this->sessionEvidence->detect($sessionEvents, max(1, (int) config('visitor-intelligence.ai.max_moments', 12))),
                    (string) $session->id,
                    $evidence,
                )
                : [];
            $sessionAnalyses[(string) $session->id] = [
                'events' => $sessionEvents,
                'moments' => $moments,
            ];
            $visualCandidateCount += collect($moments)
                ->filter(fn (array $moment): bool => (bool) ($moment['needs_visual_context'] ?? false) || (bool) ($moment['capture_candidate'] ?? false))
                ->count();
        }
        $this->progress($progress, 38, 'cross_analysis', 'Détection des moments importants et des signaux de conversion.');

        // Pick sessions that actually contain visual candidates instead of
        // assuming the first three representative sessions have a usable
        // replay. The representative order remains the deterministic
        // business-signal ranking from selectRepresentativeSessions().
        $visualSessionIds = ($plan['visual']['enabled'] ?? false)
            ? $this->selectVisualSessionIds($representative, $sessionAnalyses, (int) $plan['visual']['sessions_limit'])
            : [];

        $visualMomentsCount = 0;
        $visualCaptureCount = 0;
        $visualSessionsAttempted = 0;
        $visualErrors = [];
        $sessionContexts = [];

        foreach ($representative as $session) {
            $sessionAnalysis = $sessionAnalyses[(string) $session->id] ?? ['events' => collect(), 'moments' => []];
            $sessionEvents = $sessionAnalysis['events'];
            $moments = $sessionAnalysis['moments'];
            $visual = ['available' => false, 'moments' => []];

            if (in_array((string) $session->id, $visualSessionIds, true)) {
                $candidateMoments = collect($moments)
                    ->filter(fn (array $moment): bool => (bool) ($moment['needs_visual_context'] ?? false) || (bool) ($moment['capture_candidate'] ?? false));
                // Prefer moments that can produce an actual screenshot. Keep
                // the remaining visual moments as context, but do not let
                // non-capturable page/navigation moments consume the entire
                // per-session visual budget.
                $visualCandidates = $candidateMoments
                    ->filter(fn (array $moment): bool => (bool) ($moment['capture_candidate'] ?? false))
                    ->concat($candidateMoments->reject(fn (array $moment): bool => (bool) ($moment['capture_candidate'] ?? false)))
                    ->take((int) $plan['visual']['moments_per_session'])
                    ->values()
                    ->all();
                if ($visualCandidates !== []) {
                    $visualSessionsAttempted++;
                    $visual = $this->sessionEvidence->render($session, $visualCandidates);
                    $visualMomentsCount += count($visual['moments'] ?? []);
                    $visualCaptureCount += collect($visual['moments'] ?? [])
                        ->filter(fn (array $moment): bool => ! empty($moment['capture']))
                        ->count();
                    if (!empty($visual['error'])) {
                        $visualErrors[] = [
                            'session_id' => (string) $session->id,
                            'error' => (string) $visual['error'],
                        ];
                    }
                }
            }

            $sessionContexts[] = [
                'session_id' => (string) $session->id,
                'session_key' => (string) $session->session_key,
                'started_at' => $session->started_at?->toISOString(),
                'ended_at' => $session->ended_at?->toISOString(),
                'entry_url' => $this->safeText($session->entry_url, 500),
                'exit_url' => $this->safeText($session->exit_url, 500),
                'device' => $this->safeText($session->device, 64),
                'acquisition' => $this->sessionAcquisitionContext($session, $evidence),
                'page_count' => (int) $session->page_count,
                'duration_seconds' => $session->duration_seconds,
                'has_widget_interaction' => (bool) $session->has_widget_interaction,
                'intent_level' => $session->intent_level,
                'converted' => (bool) $session->converted,
                'summary' => $this->summaryContext($session->summary),
                'journey' => $this->journeyContext($sessionEvents),
                'events' => $this->eventContext($sessionEvents, $evidence),
                'conversations' => ($plan['include_conversations'] ?? false)
                    ? $this->applyConversationOptions(
                        $this->conversationContext($sessionEvents, $conversations, $messagesByConversation, $evidence),
                        $plan['conversation_options'] ?? [],
                    )
                    : [],
                'moments' => array_values($moments),
                // Captures are transient analysis material. They are included
                // only for the queued LLM synthesis path and are removed from
                // the persisted snapshot before it is stored or exposed.
                'visual_context' => $this->visualContext(
                    $visual,
                    (string) $session->id,
                    $evidence,
                    $includeVisualCaptures,
                ),
            ];
        }
        $this->progress($progress, 45, 'investigation', 'Reconstruction ciblée des contextes visuels et des parcours.');

        $knowledge = ($plan['include_knowledge'] ?? false)
            ? $this->knowledgeContext($site, $sessionContexts, $evidence, $sourceStatus, $configuration)
            : $this->knowledgeNotRequested($sourceStatus);
        $this->progress($progress, 46, 'live_crawl', 'Lecture contrôlée des pages publiées du site.');
        $liveCrawl = ($plan['sources']['live_crawl'] ?? false)
            ? $this->liveCrawlContext($site, $sessionContexts, $configuration, $evidence, $sourceStatus)
            : $this->liveCrawlNotRequested($sourceStatus);
        $externalPlan = $this->externalInvestigationPlan($sessionContexts, $configuration, $plan);
        $systemConversation = $includeExternal && ($plan['external_requested'] ?? false) ? Conversation::create([
            'site_id' => $site->id,
            'user_id' => null,
            'visitor_id' => null,
            'metadata' => $this->conversationCleanup->temporaryMetadata(),
        ]) : null;
        try {
            $this->progress($progress, 48, 'external_sources', 'Vérification des sources externes utiles à ce diagnostic.');
            $external = $includeExternal && ($plan['external_requested'] ?? false)
                ? $this->externalContext($site, $systemConversation, $from, $to, $externalPlan, $evidence, $sourceStatus)
                : ['google_analytics' => [], 'google_search_console' => []];
            if (! $includeExternal || ! ($plan['external_requested'] ?? false)) {
                $sourceStatus['google_analytics'] = ['status' => 'not_requested', 'successful_calls' => 0, 'requested_calls' => 0];
                $sourceStatus['google_search_console'] = ['status' => 'not_requested', 'successful_calls' => 0, 'requested_calls' => 0];
            }
        } finally {
            $this->conversationCleanup->cleanup($systemConversation);
        }
        $this->progress($progress, 52, 'synthesis', 'Préparation du snapshot complet pour le diagnostic.');

        $sourceStatus['visitor_intelligence'] = [
            'status' => ! $visitorIntelligenceEnabled ? 'not_requested' : ($sessions->isNotEmpty() ? 'ready' : 'empty'),
            'candidate_sessions' => $sessions->count(),
            'representative_sessions' => count($sessionContexts),
        ];
        if (! ($plan['include_conversations'] ?? false)) {
            $sourceStatus['conversations'] = [
                'status' => 'not_requested',
                'reason' => 'Les conversations sont désactivées dans la configuration de cet agent.',
            ];
        } else {
            $sourceStatus['conversations'] = [
                'status' => $conversationIds->isNotEmpty() ? 'ready' : 'empty',
                'linked_conversations' => $conversationIds->count(),
            ];
        }
        $sourceStatus['replay'] = [
            'status' => $visualSessionsAttempted === 0
                ? 'not_requested'
                : ($visualCaptureCount > 0 ? 'ready' : 'unavailable'),
            'reason' => $visualCandidateCount === 0
                ? 'no_visual_candidate'
                : ($visualCaptureCount === 0 ? 'replay_unavailable_or_no_rendered_capture' : 'visual_context_rendered'),
            'candidate_moments' => $visualCandidateCount,
            'selected_sessions' => count($visualSessionIds),
            'attempted_sessions' => $visualSessionsAttempted,
            'rendered_moments' => $visualMomentsCount,
            'rendered_captures' => $visualCaptureCount,
            'errors' => array_values(array_unique(array_map(
                fn (array $error): string => $error['error'],
                $visualErrors,
            ))),
        ];

        return [
            'period' => ['from' => $from->toISOString(), 'to' => $to->toISOString()],
            'overview' => $overview,
            'journey' => $this->applyJourneyOptions($journey, $plan['journey_options'] ?? []),
            'acquisition_journeys' => $acquisitionJourneyPatterns,
            'comparison' => $comparison,
            'widget' => $widget,
            'representative_sessions' => $sessionContexts,
            'knowledge_base' => $knowledge,
            'live_crawl' => $liveCrawl,
            'external' => $external,
            'source_status' => $sourceStatus,
            'evidence' => array_values($evidence),
            'investigation' => [
                'stages' => ['observe', 'correlate', 'select_representative_journeys', 'visual_investigation', 'diagnose', 'prioritize', 'recommend', 'measure'],
                'selected_sources' => array_keys(array_filter($sourceStatus, fn (array $status): bool => ($status['status'] ?? null) !== 'not_requested')),
                'external_plan' => $externalPlan,
                'visual_candidate_count' => $visualCandidateCount,
                'configuration' => $configuration,
                'plan' => $plan,
            ],
            'collection_limits' => [
                'candidate_sessions_limit' => $plan['candidate_sessions_limit'],
                'representative_sessions_limit' => $plan['representative_sessions_limit'],
                'visual_sessions_limit' => $plan['visual']['sessions_limit'],
                'raw_replay_sent_to_llm' => false,
                'visual_moments_rendered' => $visualMomentsCount,
                'visual_captures_rendered' => $visualCaptureCount,
                'visual_captures_sent_to_vision_tool' => 0,
                'visual_observations_returned' => 0,
                'visual_observations_cited' => 0,
                'live_crawl_pages' => count($liveCrawl['pages'] ?? []),
            ],
        ];
    }

    public function analyze(WebsiteGrowthAdvisorAnalysis $analysis, ?callable $progress = null): WebsiteGrowthAdvisorAnalysis
    {
        $analysis->forceFill([
            'status' => 'running',
            'started_at' => now(),
            'error_message' => null,
            'progress' => 5,
            'phase' => 'preparation',
            'progress_message' => 'Préparation de l’analyse Growth Advisor.',
        ])->save();
        $this->progress($progress, 5, 'preparation', 'Préparation de l’analyse Growth Advisor.');
        $this->progress($progress, 10, 'collection', 'Collecte des signaux observés.');

        try {
            $site = $analysis->site()->firstOrFail();
            $snapshot = $this->collectSnapshot($site, [
                'from' => $analysis->period_from,
                'to' => $analysis->period_to,
            ], (bool) $analysis->include_external, $progress, is_array($analysis->configuration_snapshot) ? $analysis->configuration_snapshot : null, true);
            $analysis->forceFill([
                // Do not persist base64 screenshots in the analysis record.
                // They are transient evidence used only by composeResult().
                'data_snapshot' => $this->withoutVisualCaptures($snapshot),
                'source_status' => $snapshot['source_status'] ?? [],
                'representative_sessions_count' => count($snapshot['representative_sessions'] ?? []),
                'visual_moments_count' => (int) data_get($snapshot, 'collection_limits.visual_moments_rendered', 0),
            ])->save();

            $this->progress($progress, 55, 'cross_analysis', 'Mise en relation des sources et des parcours.');
            $result = $this->composeResult($snapshot);
            $analysis->forceFill([
                'status' => 'ready',
                'result' => $result,
                'model' => $this->llm->lastUsedModel(),
                'completed_at' => now(),
            ])->save();
            $this->progress($progress, 100, 'completed', 'Analyse Growth Advisor terminée.');

            return $analysis->fresh();
        } catch (Throwable $exception) {
            $analysis->forceFill([
                'status' => 'failed',
                'error_message' => Str::limit($exception->getMessage(), 2000, ''),
                'completed_at' => now(),
            ])->save();
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function composeResult(array $snapshot): array
    {
        $configuration = is_array(data_get($snapshot, 'investigation.configuration'))
            ? data_get($snapshot, 'investigation.configuration')
            : $this->configurationService->defaults();
        $evidenceIds = collect($snapshot['evidence'] ?? [])->pluck('id')->filter()->values()->all();
        $fallback = $this->deterministicResult($snapshot, $configuration);

        $visualEvidence = [];
        $visualInspection = [
            'tool' => TargetedReplayVisualInspectionTool::NAME,
            'status' => 'not_requested',
            'model' => config('llm.tools.targeted_replay_visual_inspection.model'),
            'observations' => [],
            'error' => null,
        ];

        if (! config('llm.provider.api_key') && ! config('mcp.llm.api_key')) {
            $fallback = $this->finalizeGrowthResult($fallback, $snapshot, $visualInspection, $visualEvidence);
            $visualAnalysis = $this->visualEvidenceAnalysisPayload($snapshot, $visualEvidence, $visualInspection);
            $visualAnalysis['observations_cited'] = $this->countCitedVisualObservations($fallback, $visualAnalysis);
            return [
                ...$fallback,
                'visual_evidence_analysis' => $visualAnalysis,
                'meta' => ['ai_status' => 'unavailable', 'warning' => 'llm_api_key_missing'],
            ];
        }

        $visualEvidence = $this->visualEvidenceForLlm($snapshot);
        $visualInspection = $this->replayVision->inspect($visualEvidence);
        $llmSnapshot = $this->withoutVisualCaptures($snapshot);
        $llmSnapshot['visual_evidence_analysis'] = [
            'tool' => $visualInspection['tool'],
            'status' => $visualInspection['status'],
            'model' => $visualInspection['model'],
            'observation_count' => count($visualInspection['observations']),
            'observations' => $visualInspection['observations'],
            'error' => $visualInspection['error'],
            'contract' => 'Ces observations proviennent uniquement des captures des moments ciblés. Elles ne décrivent pas tous les visiteurs et ne remplacent pas les événements comportementaux.',
        ];
        $userContent = [[
            'type' => 'text',
            'text' => json_encode([
                'task' => 'Construire un diagnostic de croissance exploitable et vérifiable.',
                'configuration' => $configuration,
                'output_schema' => [
                    'executive_summary' => 'string',
                    'observed_facts' => [['id' => 'string', 'text' => 'string', 'evidence' => ['evidence_id']]],
                    'correlations' => [['id' => 'string', 'text' => 'string', 'evidence' => ['evidence_id']]],
                    'inferences' => [['id' => 'string', 'text' => 'string', 'evidence_strength' => 'strong|moderate|weak|insufficient', 'evidence' => ['evidence_id']]],
                    'diagnoses' => [['id' => 'string', 'problem' => 'string', 'where' => 'string', 'when' => 'string', 'who' => 'string', 'journey' => 'string', 'behavior' => 'string', 'cause_status' => 'supported|probable|hypothesis|unknown', 'cause' => 'string|null', 'why_not_certain' => 'string', 'evidence' => ['evidence_id'], 'missing_evidence' => ['string']]],
                    'priorities' => [['id' => 'string', 'title' => 'string', 'priority' => 'high|medium|low', 'why' => 'string', 'evidence' => ['evidence_id']]],
                    'recommendations' => [['id' => 'string', 'title' => 'string', 'priority' => 'high|medium|low', 'what' => 'string', 'where' => 'string', 'why' => 'string', 'evidence' => ['evidence_id'], 'how' => 'string', 'expected_effect' => 'string', 'measure' => 'string', 'verification_needed' => 'string', 'action' => 'string', 'basis' => 'string', 'implementation_plan' => ['status' => 'ready|needs_input|not_applicable', 'change_type' => 'content|ux|ui|tracking|seo|technical|configuration|workflow|integration|other', 'target' => 'string|null', 'target_locator' => ['page_url' => 'string|null', 'selector' => 'string|null', 'element_type' => 'string|null', 'current_text' => 'string|null', 'current_href' => 'string|null', 'operation' => 'string|null', 'evidence' => ['evidence_id']], 'operation' => 'string|null', 'current_state' => 'string|null', 'desired_state' => 'string|null', 'steps' => ['string'], 'parameters' => [['name' => 'string', 'value' => 'string']], 'files_or_resources' => ['string'], 'dependencies' => ['string'], 'acceptance_criteria' => ['string'], 'rollback' => 'string|null', 'evidence' => ['evidence_id'], 'limitations' => ['string']], 'content_proposal' => ['status' => 'ready|insufficient_evidence|not_applicable', 'purpose' => 'string', 'placement' => 'string|null', 'title' => 'string|null', 'body' => 'string|null', 'bullets' => ['string'], 'faq' => [['question' => 'string', 'answer' => 'string']], 'cta' => 'string|null', 'cta_destination' => 'string|null', 'cta_why' => 'string|null', 'evidence' => ['evidence_id'], 'limitations' => ['string']]]],
                    'tests' => [['id' => 'string', 'hypothesis' => 'string', 'change' => 'string', 'metric' => 'string', 'success_condition' => 'string', 'window' => 'string', 'evidence' => ['evidence_id']]],
                    'data_gaps' => ['string'],
                ],
                'valid_evidence_ids' => $evidenceIds,
                // This payload contains journeys, semantic events and compact
                // visual observations only. Raw rrweb chunks are never passed
                // to the model.
                'data' => $llmSnapshot,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]];

        // The synthesis model receives Qwen's bounded visual observations as
        // text. It never receives the screenshots or raw rrweb captures.
        $userContent = (string) ($userContent[0]['text'] ?? '');

        $messages = [
            [
                'role' => 'system',
                'content' => $this->advisorSystemPrompt($configuration),
            ],
            [
                'role' => 'user',
                'content' => $userContent,
            ],
        ];

        try {
            $raw = $this->llm->chatJson($messages, [
                'task' => 'website_growth_advisor',
                // Visual inspection is a separate call. The Growth Advisor
                // keeps its own model chain and only consumes visual text.
                'fallback_models' => [config('llm.tasks.website_growth_advisor.fallback_model', 'deepseek/deepseek-v3.2')],
                'temperature' => 0.1,
                'max_tokens' => 2600,
                'max_tokens_cap' => 4200,
                'detect_truncation' => true,
                'response_format' => ['type' => 'json_object'],
                'request_timeout' => (int) config('llm.tasks.website_growth_advisor.request_timeout', 90),
            ]);

            $knowledgeEvidence = collect($snapshot['knowledge_base'] ?? [])
                ->filter(fn (mixed $item): bool => is_array($item) && is_string($item['evidence_id'] ?? null))
                ->keyBy('evidence_id')
                ->all();
            $normalized = $this->normalizeResult($raw, $evidenceIds, $configuration, $knowledgeEvidence);
            if ($normalized !== []) {
                // Keep acquisition facts visible even when the LLM focuses on
                // a different part of the diagnosis. The source evidence is
                // already deterministic and must not disappear between the
                // collection snapshot and the user-facing result.
                $normalized = $this->ensureAcquisitionCoverage($normalized, $snapshot);
                $normalized = $this->finalizeGrowthResult($normalized, $snapshot, $visualInspection, $visualEvidence);
                $visualAnalysis = $this->visualEvidenceAnalysisPayload($snapshot, $visualEvidence, $visualInspection);
                $visualAnalysis['observations_cited'] = $this->countCitedVisualObservations($normalized, $visualAnalysis);
                return [
                    ...$normalized,
                    'visual_evidence_analysis' => $visualAnalysis,
                    'meta' => [
                        'ai_status' => 'ready',
                        'visual_tool' => $visualInspection['tool'],
                        'model' => $this->llm->lastUsedModel(),
                        'reasoning_model' => $this->llm->lastUsedModel(),
                        'vision_model' => $visualInspection['model'],
                        'vision_status' => $visualAnalysis['status'],
                        'visual_observations_count' => $visualAnalysis['observation_count'],
                        'models_used' => array_values(array_filter(array_unique([
                            $visualInspection['model'],
                            $this->llm->lastUsedModel(),
                        ]))),
                        'raw_replay_sent_to_llm' => false,
                    ],
                ];
            }
        } catch (Throwable) {
            // A deterministic result remains useful and explicitly indicates
            // that the optional synthesis step was unavailable.
        }

        $fallback = $this->finalizeGrowthResult($fallback, $snapshot, $visualInspection, $visualEvidence);
        $visualAnalysis = $this->visualEvidenceAnalysisPayload($snapshot, $visualEvidence, $visualInspection);
        $visualAnalysis['observations_cited'] = $this->countCitedVisualObservations($fallback, $visualAnalysis);
        return [
            ...$fallback,
            'visual_evidence_analysis' => $visualAnalysis,
            'meta' => [
                'ai_status' => 'failed',
                'warning' => 'llm_synthesis_unavailable',
                'visual_tool' => $visualInspection['tool'],
                'vision_model' => $visualInspection['model'],
                'vision_status' => $visualAnalysis['status'],
                'visual_observations_count' => $visualAnalysis['observation_count'],
                'models_used' => array_values(array_filter(array_unique([$visualInspection['model']]))),
                'raw_replay_sent_to_llm' => false,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function deterministicResult(array $snapshot, array $configuration = []): array
    {
        $kpis = collect(data_get($snapshot, 'overview.kpis', []))->keyBy('key');
        $facts = [];
        foreach (collect($snapshot['acquisition_journeys'] ?? [])->take(10) as $pattern) {
            if (! is_array($pattern) || empty($pattern['evidence_id'])) continue;
            $source = $this->safeText($pattern['source'] ?? null, 128)
                ?: $this->safeText($pattern['source_type'] ?? null, 64)
                ?: 'source inconnue';
            $facts[] = [
                'id' => 'fact:acquisition:'.substr(sha1((string) $pattern['evidence_id']), 0, 12),
                'text' => sprintf(
                    'Les parcours attribués à %s représentent %d session(s), %d conversion(s), un taux de conversion observé de %.2f %% et %d session(s) terminée(s) sans conversion observée.',
                    $source,
                    (int) ($pattern['sessions'] ?? 0),
                    (int) ($pattern['conversions'] ?? 0),
                    (float) ($pattern['conversion_rate'] ?? 0),
                    (int) ($pattern['non_converted_sessions'] ?? $pattern['abandoned_sessions'] ?? 0),
                ),
                'evidence' => [(string) $pattern['evidence_id']],
            ];
        }
        foreach (['sessions', 'conversations', 'leads', 'conversions', 'non_converted_sessions'] as $key) {
            if ($kpis->has($key)) {
                $facts[] = [
                    'id' => 'fact:kpi:'.$key,
                    'text' => (string) ($kpis[$key]['label'] ?? $key).' : '.(string) ($kpis[$key]['value'] ?? 0).'.',
                    'evidence' => ['visitor_intelligence:overview'],
                ];
            }
        }

        $diagnoses = [];
        $recommendations = [];
        $dropOff = data_get($snapshot, 'journey.drop_off', []);
        if ($dropOff !== []) {
            $firstDropOff = is_array($dropOff[0] ?? null) ? $dropOff[0] : [];
            $dropOffPage = $this->safeText($firstDropOff['exit_url'] ?? $firstDropOff['path'] ?? null, 500) ?: 'les pages de sortie observées';
            $diagnoses[] = [
                'id' => 'diagnosis:drop_off',
                'problem' => 'Des sorties sont observées sur un ou plusieurs parcours non convertis.',
                'where' => $dropOffPage,
                'when' => 'sur la période analysée',
                'who' => 'sessions représentatives non converties',
                'journey' => 'après la dernière page observée du parcours',
                'behavior' => 'sortie de session sans conversion',
                'cause_status' => 'unknown',
                'cause' => null,
                'why_not_certain' => 'Une sortie décrit un comportement observé, mais ne permet pas de distinguer une friction, une intention satisfaite ou une navigation normale.',
                'evidence' => ['visitor_intelligence:overview'],
                'missing_evidence' => ['capture visuelle du moment de sortie', 'événement de CTA ou formulaire associé', 'intention explicite de la session'],
            ];
            $recommendations[] = [
                'id' => 'recommendation:drop_off',
                'title' => 'Instrumenter le moment de sortie sur la page identifiée',
                'priority' => 'medium',
                'what' => 'Ajouter ou vérifier les événements CTA, formulaire et sortie sur la page de sortie observée.',
                'where' => $dropOffPage,
                'why' => 'Localiser le moment exact précédant la sortie avant de modifier le contenu ou le parcours.',
                'how' => 'Comparer les sessions non converties avec les clics, formulaires, conversations et éventuels moments rrweb de cette page.',
                'expected_effect' => 'Réduire l’incertitude sur la cause de sortie; aucune amélioration de conversion n’est présumée à ce stade.',
                'measure' => 'Taux de sorties par page et conversion après CTA/formulaire, comparés sur une période avant/après instrumentée.',
                'action' => 'Ajouter ou vérifier les événements CTA, formulaire et sortie sur la page de sortie observée.',
                'basis' => 'Les sorties sont observées dans les parcours non convertis; elles ne prouvent pas à elles seules une cause.',
                'evidence' => ['visitor_intelligence:overview'],
                'implementation_plan' => [
                    'status' => 'needs_input',
                    'change_type' => 'tracking',
                    'target' => $dropOffPage,
                    'current_state' => 'Une sortie de session est observée, mais le CTA ou le formulaire déclencheur n’est pas identifié dans les preuves disponibles.',
                    'desired_state' => 'Chaque interaction pertinente de cette page est mesurée jusqu’à la conversion.',
                    'steps' => [
                        'Identifier sur la page le CTA, le formulaire et l’événement de sortie concernés.',
                        'Définir les événements et paramètres de mesure avant toute modification.',
                    ],
                    'parameters' => [],
                    'files_or_resources' => [$dropOffPage],
                    'dependencies' => ['Identification de l’élément interactif exact sur la page.'],
                    'acceptance_criteria' => ['Les clics, soumissions et conversions sont reliés sur une période comparable.'],
                    'rollback' => 'Retirer les événements d’instrumentation ajoutés sans modifier le contenu de la page.',
                    'evidence' => ['visitor_intelligence:overview'],
                    'limitations' => ['La cause de la sortie reste inconnue avec les données actuelles.'],
                ],
                'content_proposal' => null,
            ];
        }

        $result = [
            'executive_summary' => 'Synthèse déterministe disponible; la synthèse LLM n’a pas produit de résultat exploitable.',
            'observed_facts' => $facts,
            'correlations' => [],
            'inferences' => [],
            'diagnoses' => $diagnoses,
            'priorities' => [],
            'recommendations' => $recommendations,
            'tests' => [],
            'data_gaps' => collect($snapshot['source_status'] ?? [])
                ->filter(fn ($s) => in_array($s['status'] ?? null, ['unavailable', 'partial', 'not_requested'], true))
                ->map(fn ($s, $key) => ($s['status'] ?? null) === 'not_requested'
                    ? "Source {$key} non sollicitée selon le plan d’investigation."
                    : "Source {$key} indisponible ou incomplète.")
                ->values()->all(),
        ];
        return $this->applyOutputPreferences($result, $configuration);
    }

    private function advisorSystemPrompt(array $configuration = []): string
    {
        $requirements = sprintf(
            "\n\nCONFIGURATION ACTIVE DU TENANT\nObjectif principal : %s\nObjectifs secondaires : %s\nZones prioritaires : %s\nProfondeur : %s\nExigence de preuve : %s\nAnalyse causale obligatoire : %s\nHypothèses autorisées : %s\nPlan de mesure obligatoire : %s\nNiveau de recommandation : %s\nDétail d’implémentation : %s\nCritères de priorisation : %s\nDivulgation d’incertitude obligatoire : %s\nKPI principal : %s\nKPI secondaires : %s\n\nRespecte ces choix comme des contraintes de sortie : ne demande pas une source désactivée, n’invente pas une mesure absente et réduis la réponse si le mode choisi est diagnostic_only.",
            (string) ($configuration['primary_objective'] ?? 'lead_generation'),
            implode(', ', (array) ($configuration['secondary_objectives'] ?? [])),
            implode(', ', (array) ($configuration['priority_areas'] ?? [])),
            (string) ($configuration['analysis_depth'] ?? 'standard'),
            (string) ($configuration['evidence_requirement'] ?? 'medium'),
            ! empty($configuration['require_cause_analysis']) ? 'oui' : 'non',
            ! empty($configuration['allow_hypotheses']) ? 'oui' : 'non',
            ! empty($configuration['require_measurement_plan']) ? 'oui' : 'non',
            (string) ($configuration['recommendation_depth'] ?? 'actionable'),
            (string) ($configuration['implementation_detail'] ?? 'practical'),
            implode(', ', (array) ($configuration['prioritization_criteria'] ?? [])),
            ! empty($configuration['require_uncertainty_disclosure']) ? 'oui' : 'non',
            (string) ($configuration['primary_kpi'] ?? 'conversions'),
            implode(', ', (array) ($configuration['secondary_kpis'] ?? [])),
        );

        return <<<'PROMPT'
Tu es Website Growth Advisor d’ELChat, un agent d’analyse de croissance web fondé sur les preuves.

Ta mission est de transformer les données disponibles en un diagnostic exploitable, vérifiable, contextualisé et mesurable, sans inventer de faits, de causalités, de données ou de connaissances propres au site.

Tu ne dois pas simplement résumer les données fournies. Tu dois raisonner à partir des preuves disponibles, identifier les problèmes réellement observables, déterminer ce qui peut ou ne peut pas être expliqué, puis proposer uniquement des actions proportionnées au niveau de preuve.

Tu dois toujours privilégier :

PRÉCISION > QUANTITÉ
PREUVE > INTUITION
DIAGNOSTIC > OPINION
SPÉCIFICITÉ > CONSEIL GÉNÉRIQUE
VÉRIFIABILITÉ > AFFIRMATION

1. PROTOCOLE D’ANALYSE OBLIGATOIRE

Tu dois raisonner selon cette chaîne :

Observe → Correlate → Investigate → Diagnose → Prioritize → Recommend → Measure

Ne saute pas directement de data à diagnoses ou recommendations.

OBSERVE

Commence par identifier uniquement les faits directement observés dans les données.
Un fait observé doit être directement supporté par les données disponibles.

Une page qui reçoit beaucoup de visites, des sessions terminées sans conversion observée ou un taux d’abandon explicitement mesuré, un CTA peu utilisé, une question récurrente, une requête Search Console avec des impressions mais peu de clics ou plusieurs parcours présentant le même comportement peuvent être des observations si les données les démontrent.

Ne transforme jamais une observation en causalité. Une sortie après une page ne prouve pas que cette page est mauvaise ou que son formulaire est trop long.

CORRELATE

Recherche ensuite les relations observables entre plusieurs sources ou dimensions : comportement visiteur, page, événement, CTA, conversion, acquisition, conversation, Knowledge, Google Analytics, Search Console, replay, parcours, période et segment.

Une corrélation n’est jamais automatiquement une causalité. Si plusieurs sources convergent, indique-le explicitement, sans dépasser ce que les preuves permettent d’affirmer.

INVESTIGATE

Avant de poser un diagnostic causal, détermine si les données disponibles sont suffisantes. Cherche uniquement les preuves pertinentes pour réduire une incertitude réelle.

Utilise Visitor Intelligence pour les parcours, les replays pour les comportements précis, les conversations pour les objections et questions, Knowledge pour le contexte business, le live_crawl pour vérifier la page réellement publiée, Analytics pour les volumes et segments, Search Console pour les intentions SEO et les données visuelles lorsqu’elles existent et sont pertinentes.

N’utilise pas toutes les sources systématiquement. Ne prétends jamais avoir investigué une source absente des données. Ne suppose jamais qu’une source est disponible simplement parce qu’elle existe dans l’architecture ELChat.

DIAGNOSE

Un diagnostic doit décrire un problème localisé et vérifiable. Lorsque cela est pertinent, caractérise-le par PROBLEM, WHERE, WHEN, WHO, JOURNEY, BEHAVIOR et CAUSE. Quand live_crawl fournit un sélecteur, une URL, un titre de section, un formulaire ou un lien précis, utilise cette cible au lieu d’un libellé générique.

Le diagnostic doit distinguer explicitement :
- supported : la cause est directement démontrée par les preuves ;
- probable : plusieurs éléments convergent fortement vers cette cause sans la démontrer complètement ;
- hypothesis : explication plausible mais insuffisamment démontrée ;
- unknown : les données ne permettent pas de déterminer la cause.

Il est parfaitement valide de conclure que la cause est inconnue avec les données disponibles. Ne force jamais une causalité pour produire une réponse plus complète.

Une evidence_id valide ne signifie pas automatiquement que cette preuve supporte le claim. Vérifie que la preuve existe, mesure réellement ce qui est affirmé, couvre la bonne période et le bon périmètre, que l’échantillon est pertinent, qu’il n’existe pas de preuve contradictoire et qu’elle démontre une causalité plutôt qu’une simple association.

2. SÉPARATION STRICTE DES NIVEAUX DE CONNAISSANCE

Sépare sans ambiguïté :
- observed_facts : ce qui est directement mesuré ;
- correlations : relations observées entre plusieurs faits ou sources ;
- inferences : interprétations prudentes dérivées des preuves ;
- diagnoses : problèmes localisés et explications possibles avec niveau de causalité explicite ;
- priorities : importance opérationnelle des problèmes identifiés ;
- recommendations : actions concrètes proposées à partir des preuves ;
- tests : expériences permettant de confirmer ou d’infirmer une hypothèse.

Ne mélange jamais fait, interprétation, hypothèse et recommandation dans une seule affirmation présentée comme certaine.

3. AUCUNE CONNAISSANCE EXTERNE COMME PREUVE DU SITE

Ton savoir général sur l’UX, le CRO, le SEO, le marketing, l’e-commerce, le design ou les bonnes pratiques peut uniquement servir à générer une hypothèse ou un test. Il ne constitue jamais une preuve concernant ce site.

Ne transforme jamais une best practice en diagnostic. Si les données ne démontrent pas une cause, formule une hypothèse conditionnelle ou un test ciblé.

4. CAUSALITÉ ET FORCE DES PREUVES

Utilise supported uniquement lorsque les preuves disponibles démontrent directement la relation causale.
Utilise probable lorsque plusieurs éléments indépendants convergent fortement mais qu’une démonstration complète manque.
Utilise hypothesis lorsqu’il existe une explication plausible mais insuffisamment étayée.
Utilise unknown lorsque les données ne permettent pas raisonnablement de déterminer la cause.

Lorsque cause_status vaut unknown, ne fabrique pas de cause. Lorsque cause_status vaut hypothesis, ne présente pas la recommandation comme une solution certaine.

5. FORCE DE PREUVE ET CONFIANCE

N’utilise pas de pourcentage de confiance arbitraire. Ne produis pas de valeurs comme 73 %, 82 % ou 91 % sauf si elles proviennent directement des données fournies.

Pour exprimer la force de l’évidence, utilise qualitativement strong, moderate, weak ou insufficient. Cette force dépend notamment de la qualité des données, de la pertinence, de la convergence entre sources, du volume, de la précision temporelle et segmentaire, de la répétition, de la présence de preuves directes et des contradictions éventuelles.

6. CONTRADICTIONS ET DONNÉES INCOMPATIBLES

Si deux sources semblent contradictoires, ne les fusionne pas artificiellement et ne choisis pas arbitrairement une source. Identifie la contradiction, vérifie période, périmètre, segment et définition, puis indique l’incertitude restante.

Une contradiction peut devenir une data_gap ou une limitation du diagnostic. Ne présente jamais comme certain un résultat qui dépend d’une donnée contradictoire non résolue.

7. TEMPORALITÉ ET COMPARABILITÉ

Respecte strictement les périodes disponibles. Ne compare pas directement deux périodes, segments ou fenêtres de sources qui ne sont pas comparables. Si une comparaison temporelle n’est pas suffisamment comparable, indique cette limitation et ne déduis pas de tendance.

8. QUALITÉ DES DONNÉES

Prends en compte les petits volumes, données manquantes, périodes courtes, tracking incomplet, sources partielles, métriques ambiguës, événements absents, replays absents, conversations insuffisantes et absence de contexte business.

Lorsqu’une limite empêche un diagnostic fiable, dis-le. Il vaut mieux retourner un diagnostic incomplet mais honnête qu’un diagnostic complet mais inventé.

9. VISITOR INTELLIGENCE ET PARCOURS

Lorsque Visitor Intelligence est disponible et pertinent, analyse source d’acquisition, page d’entrée, séquence des pages, durée, profondeur, clics, CTA, formulaires, conversions, sessions terminées sans conversion observée, sorties explicitement signalées, retours en arrière, répétitions, interactions, événements, moments critiques et parcours complets.

Les replays représentent des parcours réels. Ne considère jamais un replay isolé comme la preuve d’un comportement généralisé sans indication suffisante de répétition.

9 BIS. SOURCES D’ACQUISITION ET COMPARAISON DES PARCOURS

Exploite toujours representative_sessions[*].acquisition et acquisition_journeys lorsque Visitor Intelligence est disponible. Chaque parcours peut citer acquisition.evidence_id ; utilise cette preuve pour relier précisément le parcours à son type de source, sa source, son medium, sa campagne, sa plateforme et son niveau de confiance d’attribution.

Utilise acquisition_journeys pour comparer les groupes de parcours par source : volume, conversions, taux de conversion, sessions terminées sans conversion observée, intention, interaction avec le widget, profondeur, durée, pages d’entrée et pages de sortie. Une session terminée sans conversion ne prouve pas un abandon : réserve ce terme à un signal explicite. Utilise les parcours représentatifs pour expliquer un pattern agrégé, mais ne présente jamais un parcours individuel comme représentatif de toute sa source sans répétition suffisante.

Une différence entre sources est une association observée, pas une causalité. Ne dis jamais qu’une source « cause » la conversion ou l’abandon uniquement parce que ses taux diffèrent. Tiens compte du volume, de la confiance d’attribution, de la période, du type de visiteurs, des pages d’entrée et des parcours comparés. Si la source est inconnue ou faiblement attribuée, indique-le et limite la conclusion.

Lorsque acquisition_journeys contient au moins un groupe, produis au minimum un observed_fact par groupe de source avec son evidence_id, son volume, ses conversions, son taux de conversion, ses sessions terminées sans conversion observée et, lorsqu’elles existent, ses principales pages d’entrée et de sortie. Lorsqu’un diagnostic ou une recommandation dépend d’une différence entre sources, indique explicitement la ou les sources concernées dans who ou journey et cite les preuves de groupe et de parcours correspondantes.

10. ANALYSE DES REPLAYS

Ne demande jamais mentalement à analyser indistinctement tous les replays. Pour un grand volume, raisonne selon :

agrégation déterministe → patterns → problèmes candidats → parcours représentatifs → preuves ciblées → raisonnement

Ne traite jamais 1000 parcours comme 1000 analyses LLM indépendantes. Lorsque les données fournissent des parcours représentatifs, utilise-les comme preuves qualitatives des comportements identifiés. Si aucun replay représentatif n’est disponible, ne prétends pas avoir observé le comportement visuel.

Lorsque visual_evidence_analysis.status vaut ready et que des observations visuelles factuelles sont disponibles, utilise au moins l’une d’elles dans un diagnosis ou une recommendation lorsqu’elle est pertinente pour le problème identifié, en citant son evidence_id replay_visual:* correspondant. Ne remplace pas une observation visuelle disponible par un diagnostic uniquement quantitatif. Les champs visual_facts décrivent uniquement ce qui est visible dans la capture ; les événements associés dans event_ids, event_types et event_context sont nécessaires pour qualifier le comportement et ne doivent pas être confondus avec ce que l’image prouve.

11. CONVERSATIONS

Utilise les conversations pour comprendre objections, questions récurrentes, incompréhensions, prix, demandes de devis, informations manquantes, blocages, intentions commerciales et attentes utilisateur.

Une conversation isolée ne doit pas automatiquement devenir une tendance générale. Lorsqu’un signal conversationnel est récurrent et cohérent avec un comportement observé, indique la convergence entre les sources.

12. KNOWLEDGE / CONTEXTE BUSINESS

Utilise Knowledge lorsqu’il est pertinent pour comprendre produits, services, offres, propositions de valeur, caractéristiques, conditions, informations disponibles, objections possibles ou contenu manquant.

Knowledge sert à comprendre le contexte réel de l’entreprise. Ne contredis jamais une information business connue sans signaler explicitement le conflit. N’utilise pas Knowledge systématiquement lorsqu’il n’apporte rien au diagnostic.

12 BIS. LIVE_CRAWL / PAGE RÉELLEMENT PUBLIÉE

Lorsque live_crawl est disponible, utilise-le pour vérifier l’état réellement publié des pages demandées ou découvertes dans le périmètre borné : titre, description, hiérarchie de titres, sections, liens, éléments interactifs, formulaires, données structurées et contenu visible.

Le live_crawl est une lecture éphémère et en lecture seule. Il ne prouve pas le comportement JavaScript après interaction, les pages authentifiées, les variantes personnalisées, les performances réelles ni les données de conversion. Ne prétends pas avoir inspecté une page qui n’apparaît pas dans live_crawl. Si le rendu dépend de JavaScript ou si la page n’a pas pu être lue, indique précisément cette limite.

Quand une recommandation cible une page, un élément, une règle SEO, une correction technique ou une modification de contenu, relie-la si possible à l’evidence_id live_crawl de la page concernée et à l’evidence_id Knowledge qui justifie le contenu métier. Le futur agent d’implémentation doit pouvoir distinguer ce qui est observé dans le code/DOM publié de ce qui est validé par Knowledge.

13. RECOMMANDATIONS : SEUIL DE PREUVE OBLIGATOIRE

Une recommandation doit être proportionnée à la force des preuves.

- preuve forte : recommandation concrète et spécifique ;
- preuve modérée : recommandation conditionnée et accompagnée de sa limite ;
- preuve faible : hypothèse ou test ;
- preuve insuffisante : collecte de donnée, instrumentation, source à consulter ou parcours à examiner.

14. NE PAS CONFONDRE PROBLÈME ET SOLUTION

Ne déduis pas la solution avant d’avoir caractérisé le problème. Si les visiteurs abandonnent après une section, ne prétends pas que le CTA est la cause sans preuve. Propose une investigation ou un test si le mécanisme reste incertain.

15. RECOMMANDATIONS OPÉRATIONNELLES

Une recommandation générique est insuffisante. Évite améliorer l’UX, optimiser la page, améliorer le SEO, améliorer le CTA, simplifier le parcours ou améliorer la conversion sans contexte.

Sont également interdites les formulations vagues comme « activer l’engagement », « ajouter un CTA », « promouvoir le widget », « améliorer l’engagement » ou « mettre en place un suivi ». Pour chacune, donne la page, la section ou l’élément exact, le changement précis, le texte ou libellé proposé lorsqu’il s’agit de contenu, et la raison fondée sur les preuves et Knowledge. Si ces détails ne sont pas justifiés, retourne un test ou une limite de diagnostic plutôt qu’une recommandation générique.

Chaque recommandation doit préciser :
- WHAT : ce qui doit être changé ;
- WHERE : l’emplacement précis ;
- WHY : pourquoi cette modification est justifiée ;
- EVIDENCE : les preuves qui la supportent ;
- HOW : comment l’implémenter concrètement ;
- EXPECTED_EFFECT : le changement comportemental attendu ;
- MEASURE : comment vérifier objectivement le résultat.

La recommandation doit être suffisamment spécifique pour qu’une équipe produit, marketing ou développement puisse agir sans deviner.

Chaque recommandation doit aussi contenir un implementation_plan indépendant du type de changement. Les exemples de CTA, titre, page et contenu ne sont que des cas particuliers : applique le même niveau de précision à une modification UX/UI, un événement de tracking, une règle SEO, une correction technique, une configuration, un workflow, une intégration ou une action sur une plateforme connectée.

Dans implementation_plan, indique :
- status=ready uniquement si un agent peut agir sans deviner la cible, l’opération ou les paramètres ;
- status=needs_input si une information vérifiable manque, en précisant exactement laquelle dans limitations ;
- change_type parmi content, ux, ui, tracking, seo, technical, configuration, workflow, integration ou other ;
- target : URL, route, composant, fichier, sélecteur, événement, ressource de plateforme ou autre cible exacte ; reprends les sélecteurs et URLs du live_crawl lorsqu’ils existent ;
- current_state et desired_state ;
- steps ordonnées, parameters concrets, files_or_resources, dependencies, acceptance_criteria et rollback.

Un futur agent d’implémentation doit pouvoir exécuter uniquement les plans ready et laisser les plans needs_input en attente. Ne transforme jamais une suggestion vague en plan ready et ne prétends jamais avoir appliqué le changement.

15 BIS. PROPOSITIONS DE CONTENU FONDÉES SUR KNOWLEDGE

Lorsque la recommandation consiste à ajouter, réécrire ou enrichir une section, une page, une FAQ, une proposition de valeur ou un CTA, utilise en plus les résultats Knowledge marqués content_proposal pour proposer un contenu directement exploitable. Un contenu n’est confirmé que si l’evidence_id cité correspond à une requête Knowledge de purpose=content_proposal, dont le statut est ready et dont les résultats retournés sont non vides. content_proposal est une spécialisation éditoriale de implementation_plan, pas le contrat général d’implémentation.

Dans ce cas, une recommandation générique est interdite. Indique obligatoirement l’emplacement exact de la modification (page et section), le titre exact à afficher, le contenu proposé, le libellé exact du CTA, sa destination et pourquoi ce CTA est adapté au problème observé plutôt qu’un autre. Le contenu doit être fondé sur les extraits Knowledge du tenant courant, avec leurs evidence_id.

Dans content_proposal, indique le statut :
- ready uniquement si le contenu est soutenu par des extraits Knowledge pertinents et les evidence_id correspondants ; un simple identifiant knowledge:* ou une requête Knowledge sans résultat ne suffit pas ;
- insufficient_evidence si la structure est pertinente mais que Knowledge ne permet pas de rédiger un texte fiable ;
- not_applicable lorsqu’aucun contenu n’est nécessaire.

Un contenu prêt à utiliser peut contenir un titre, un body, des bullets, une FAQ et un CTA. N’invente jamais de prix, caractéristiques, garanties, résultats, délais ou promesses absents des extraits Knowledge. Si l’information manque, propose seulement une structure ou formule une limitation explicite. Sépare toujours le contenu confirmé par Knowledge des suggestions générales de rédaction.

15 TER. VÉRIFICATION DE L’EXISTANT AVANT « AJOUTER »

Avant d’utiliser le verbe ajouter pour un CTA, un formulaire, une section, un bloc ou un lien, vérifie dans live_crawl.pages[].ctas, .forms, .sections et .links de la page ciblée si un élément équivalent existe déjà.

Si un élément équivalent existe : n’utilise pas ajouter. Utilise reformuler, repositionner, renforcer ou remplacer, cite le texte exact et le selector de l’élément existant comme target_locator, et explique en quoi l’élément actuel est insuffisant (position, formulation, visibilité, contraste avec le comportement observé).

Si aucun élément équivalent n’existe dans live_crawl pour cette page précise : tu peux utiliser ajouter, à condition de le justifier explicitement par l’absence constatée dans live_crawl et non par une supposition. Si la page ou l’inventaire correspondant n’est pas disponible, retourne needs_input.

16. RECOMMANDATION VS TEST

Utilise recommendation lorsque les preuves justifient suffisamment une action. Utilise test lorsqu’une hypothèse doit encore être validée. Si les données montrent seulement beaucoup de sorties après une section, ne prétends pas que le CTA est la cause : propose un test ou une investigation ciblée.

17. PRIORISATION

Utilise uniquement high, medium ou low. La priorité doit tenir compte, lorsque disponible, du volume, de l’impact business, de la fréquence, de la sévérité, de l’importance dans le parcours, de la convergence des sources, de la qualité des preuves, de la proximité avec une conversion et de la répétition.

Ne fabrique pas un score arbitraire. Ne donne pas high uniquement parce qu’un problème semble intéressant.

18. NOMBRE DE RECOMMANDATIONS

Ne remplis jamais artificiellement la réponse. Deux recommandations concrètes et fortement justifiées valent mieux que dix recommandations génériques. La précision est prioritaire sur la quantité.

19. EVIDENCE_ID

Utilise uniquement les evidence_id présents dans valid_evidence_ids. Une evidence_id ne doit être citée que si son contenu supporte réellement l’affirmation.

N’invente jamais d’evidence_id, session, replay, conversation, événement, statistique, métrique, source, résultat ou donnée business. Si aucune preuve pertinente n’existe, utilise une liste vide lorsque le schéma l’autorise et indique clairement ce qui manque.

20. DATA GAPS

Utilise data_gaps pour identifier précisément les informations qui empêchent d’aller plus loin. Une data gap doit être actionnable. Précise, par exemple, qu’un événement de soumission de formulaire manque et empêche de distinguer un abandon avant interaction d’un échec après soumission.

Les connecteurs externes sont interrogés par Growth Advisor avant la synthèse. Ne demande jamais au tenant de « collecter les données Search Console », « mettre en place un suivi Search Console » ou « récupérer les données GA4 ». Si la source est ready ou partial dans source_status, utilise les données déjà retournées. Si elle est unavailable, signale uniquement que l’appel du connecteur n’a pas fourni de données et limite la conclusion ; ne transforme pas cette indisponibilité en tâche manuelle pour le tenant.

21. BOUCLE DE MESURE

Toute recommandation importante doit fermer la boucle :

Action → changement attendu → métrique → observation → décision

Une recommandation sans méthode de mesure est incomplète. Le champ measure doit indiquer une mesure observable, comme le taux de clic CTA vers conversion sur le même segment avant/après sur une fenêtre comparable.

22. CONTEXTE TENANT / BUSINESS

Le site analysé est un tenant isolé. Ne mélange jamais les données d’un autre tenant, conversations, Knowledge, Analytics, Search Console, Visitor Intelligence, replays ou résultats d’une autre analyse.

Toutes les conclusions doivent être basées uniquement sur le contexte fourni pour le tenant courant.

23. ACTIONS AUTORISÉES

Tu es un conseiller analytique. Tu proposes des actions. Tu ne prétends jamais avoir exécuté une modification du site, changé un CTA, modifié un contenu, lancé une campagne ou effectué une opération externe. Toute action doit être formulée comme une recommandation ou un test.

Le crawl live ne constitue pas une modification et ne doit jamais être présenté comme une indexation ou une mise à jour de Knowledge. Ne demande pas au tenant de crawler manuellement les pages : utilise les pages retournées par live_crawl ou indique l’URL exacte qui n’a pas pu être lue.

24. EXECUTIVE SUMMARY

executive_summary doit être une synthèse factuelle et utile contenant idéalement le principal problème observé, la force générale des preuves, l’opportunité ou le risque principal, la principale incertitude ou limite et les actions prioritaires lorsqu’elles sont suffisamment justifiées.

25. ORDRE DE DÉCISION OBLIGATOIRE

Avant de produire le JSON final, applique mentalement cette séquence :
1. Qu’est-ce qui est réellement observé ?
2. Quelles relations entre les données sont réellement visibles ?
3. Quel problème mérite investigation ?
4. Quelles preuves supplémentaires sont pertinentes ?
5. Les preuves permettent-elles d’expliquer le problème ?
6. Existe-t-il une contradiction ou une limite ?
7. Quel niveau de causalité est réellement justifié ?
8. Quelle est la force de l’évidence ?
9. Quelle priorité est justifiable ?
10. Une recommandation concrète est-elle suffisamment justifiée ?
11. Sinon, faut-il produire une hypothèse ou un test ?
12. Comment mesurer le résultat ?
13. Toutes les affirmations importantes ont-elles une evidence_id pertinente ?
14. Ai-je utilisé une connaissance générale comme preuve du site ?
15. Ai-je inventé ou extrapolé une donnée ?
16. Ai-je produit une recommandation générique ?
17. Puis-je supprimer une recommandation sans perdre de valeur ?

Si une affirmation ne peut pas être suffisamment justifiée, réduis son niveau de certitude ou supprime-la.

26. RÈGLE D’ABSTENTION

Tu dois pouvoir dire cause inconnue, données insuffisantes, preuve faible, hypothèse à tester, sources contradictoires ou impossible de conclure avec les données disponibles. Ce n’est pas un échec.

27. FORMAT DE SORTIE

Réponds exclusivement avec un objet JSON valide conforme exactement au schéma fourni. N’ajoute aucun Markdown, introduction, conclusion hors JSON, commentaire ou explication avant ou après le JSON. Respecte exactement les types, valeurs autorisées et noms de champs du schéma.

28. SCHÉMA DE SORTIE

{
  "executive_summary": "string",
  "observed_facts": [
    {"id": "string", "text": "string", "evidence": ["evidence_id"]}
  ],
  "correlations": [
    {"id": "string", "text": "string", "evidence": ["evidence_id"]}
  ],
  "inferences": [
    {"id": "string", "text": "string", "evidence_strength": "strong|moderate|weak|insufficient", "evidence": ["evidence_id"]}
  ],
  "diagnoses": [
    {
      "id": "string",
      "problem": "string",
      "where": "string",
      "when": "string",
      "who": "string",
      "journey": "string",
      "behavior": "string",
      "cause_status": "supported|probable|hypothesis|unknown",
      "cause": "string|null",
      "why_not_certain": "string",
      "evidence": ["evidence_id"],
      "missing_evidence": ["string"]
    }
  ],
  "priorities": [
    {"id": "string", "title": "string", "priority": "high|medium|low", "why": "string", "evidence": ["evidence_id"]}
  ],
  "recommendations": [
    {
      "id": "string",
      "title": "string",
      "priority": "high|medium|low",
      "what": "string",
      "where": "string",
      "why": "string",
      "evidence": ["evidence_id"],
      "how": "string",
      "expected_effect": "string",
      "measure": "string",
      "verification_needed": "string",
      "action": "string",
      "basis": "string",
      "implementation_plan": {
        "status": "ready|needs_input|not_applicable",
        "change_type": "content|ux|ui|tracking|seo|technical|configuration|workflow|integration|other",
        "target": "string|null",
        "target_locator": {
          "page_url": "string|null",
          "selector": "string|null",
          "element_type": "string|null",
          "current_text": "string|null",
          "current_href": "string|null",
          "operation": "string|null",
          "evidence": ["evidence_id"]
        },
        "operation": "string|null",
        "current_state": "string|null",
        "desired_state": "string|null",
        "steps": ["string"],
        "parameters": [{"name": "string", "value": "string"}],
        "files_or_resources": ["string"],
        "dependencies": ["string"],
        "acceptance_criteria": ["string"],
        "rollback": "string|null",
        "evidence": ["evidence_id"],
        "limitations": ["string"]
      },
      "content_proposal": {
        "status": "ready|insufficient_evidence|not_applicable",
        "purpose": "string",
        "placement": "string|null",
        "title": "string|null",
        "body": "string|null",
        "bullets": ["string"],
        "faq": [{"question": "string", "answer": "string"}],
        "cta": "string|null",
        "cta_destination": "string|null",
        "cta_why": "string|null",
        "evidence": ["evidence_id"],
        "limitations": ["string"]
      }
    }
  ],
  "tests": [
    {
      "id": "string",
      "hypothesis": "string",
      "change": "string",
      "metric": "string",
      "success_condition": "string",
      "window": "string",
      "evidence": ["evidence_id"]
    }
  ],
  "data_gaps": ["string"]
}

29. CONTRÔLE FINAL AVANT RÉPONSE

Avant de retourner le JSON, vérifie silencieusement :
- toutes les evidence_id existent dans valid_evidence_ids ;
- chaque evidence_id citée supporte réellement le claim associé ;
- aucun fait ni causalité n’a été inventé ;
- les corrélations ne sont pas présentées comme des causalités ;
- les hypothèses sont distinguées des faits ;
- les recommandations sont proportionnées à la force des preuves ;
- les recommandations faibles sont transformées en tests ou investigations ;
- les causes inconnues restent inconnues ;
- les contradictions sont signalées ;
- les périodes sont comparables ;
- les données insuffisantes sont signalées ;
- les recommandations sont spécifiques au problème observé ;
- aucune recommandation générique n’est produite ;
- le nombre de recommandations reste limité ;
- chaque recommandation possède WHAT, WHERE, WHY, EVIDENCE, HOW, EXPECTED_EFFECT et MEASURE ;
- toute recommandation de contenu, de section ou de CTA possède une proposition de contenu précise avec placement, titre, texte, CTA, destination et justification ;
- toute recommandation possède un implementation_plan ; un plan absent, vague ou non vérifiable doit être retourné avec status=needs_input, jamais comme une action prête à exécuter ;
- toute recommandation status=ready cite au moins une preuve live_crawl:* ou replay_visual:* ; une affirmation comportementale sur un visiteur, un clic, une visibilité, une pause, un scroll ou une sortie exige en plus une observation issue d’une capture replay et les événements associés ;
- status=ready est interdit sans target exact, target_locator complet pour une cible DOM (page_url, selector et element_type), operation explicite, current_state, desired_state, parameters concrets, steps, acceptance_criteria, rollback et evidence pertinente ;
- pour une cible DOM, le selector doit provenir d’une page live_crawl et target_locator.evidence doit citer l’evidence_id live_crawl de cette page ; n’invente jamais de selector ;
- avant toute formulation « ajouter », vérifie live_crawl.pages[].ctas, .forms, .sections et .links de la page exacte ; si un équivalent existe, reformule, repositionne, renforce ou remplace au lieu d’ajouter ;
- si le dépôt, le CMS, le composant ou le fichier source n’est pas connecté, indique needs_input même si le DOM publié est connu ;
- chaque test possède une hypothèse, un changement, une métrique et une condition de succès ;
- aucune connaissance générale n’est présentée comme preuve du site ;
- aucun résultat d’un autre tenant n’est utilisé ;
- tu n’affirmes jamais avoir exécuté une action ;
- la réponse finale est un JSON strictement valide.

Principe final :

Si les données permettent de conclure, conclus clairement.
Si elles permettent seulement d’émettre une hypothèse, formule une hypothèse.
Si elles permettent de proposer un test, propose un test.
Si elles ne permettent pas de savoir, dis que tu ne sais pas.
Ne remplis jamais les trous avec de l’imagination.
PROMPT
            . $requirements;
    }

    /** @return array<string, mixed> */
    private function normalizeResult(array $raw, array $validEvidenceIds, array $configuration = [], array $knowledgeEvidence = []): array
    {
        $normalizeEvidence = function (mixed $items) use ($validEvidenceIds): array {
            $items = is_array($items) ? $items : [];
            return collect($items)->map(function ($item) use ($validEvidenceIds): ?string {
                $id = is_array($item) ? ($item['evidence_id'] ?? $item['id'] ?? null) : $item;
                return is_string($id) && in_array($id, $validEvidenceIds, true) ? $id : null;
            })->filter()->unique()->take(10)->values()->all();
        };
        $text = fn (mixed $value, int $limit = 1200): ?string => $this->safeText($value, $limit);
        $claims = function (mixed $value, bool $withEvidenceStrength = false) use ($normalizeEvidence, $text): array {
            return collect(is_array($value) ? $value : [])->map(function ($item) use ($normalizeEvidence, $text, $withEvidenceStrength): ?array {
                if (! is_array($item)) return null;
                $claim = $text($item['text'] ?? $item['claim'] ?? null);
                if (! $claim) return null;
                $result = ['id' => $text($item['id'] ?? null, 100) ?: 'claim:'.substr(sha1($claim), 0, 12), 'text' => $claim, 'evidence' => $normalizeEvidence($item['evidence'] ?? [])];
                if ($withEvidenceStrength) {
                    $result['evidence_strength'] = in_array($item['evidence_strength'] ?? null, ['strong', 'moderate', 'weak', 'insufficient'], true)
                        ? $item['evidence_strength']
                        : 'insufficient';
                }
                return $result;
            })->filter()->values()->take(30)->all();
        };
        $priorities = $this->normalizeStructuredItems($raw['priorities'] ?? [], $normalizeEvidence, $text, 'priority', ['high', 'medium', 'low']);
        $diagnoses = collect(is_array($raw['diagnoses'] ?? null) ? $raw['diagnoses'] : [])->map(function ($item) use ($normalizeEvidence, $text): ?array {
            if (! is_array($item)) return null;
            $problem = $text($item['problem'] ?? $item['title'] ?? $item['text'] ?? null, 1000);
            if (! $problem) return null;
            $status = in_array($item['cause_status'] ?? null, ['supported', 'probable', 'hypothesis', 'unknown'], true)
                ? $item['cause_status']
                : 'unknown';
            return [
                'id' => $text($item['id'] ?? null, 100) ?: 'diagnosis:'.substr(sha1($problem), 0, 12),
                'problem' => $problem,
                'where' => $text($item['where'] ?? null, 600),
                'when' => $text($item['when'] ?? null, 400),
                'who' => $text($item['who'] ?? null, 400),
                'journey' => $text($item['journey'] ?? null, 600),
                'behavior' => $text($item['behavior'] ?? null, 600),
                'cause_status' => $status,
                'cause' => $text($item['cause'] ?? null, 900),
                'why_not_certain' => $text($item['why_not_certain'] ?? null, 900),
                'evidence' => $normalizeEvidence($item['evidence'] ?? []),
                'missing_evidence' => collect(is_array($item['missing_evidence'] ?? null) ? $item['missing_evidence'] : [$item['missing_evidence'] ?? null])
                    ->map(fn ($value) => $text($value, 400))->filter()->unique()->take(10)->values()->all(),
            ];
        })->filter()->values()->take(30)->all();
        $recommendations = $this->normalizeRecommendations($raw['recommendations'] ?? [], $normalizeEvidence, $text, $configuration, $knowledgeEvidence);
        $tests = $this->normalizeStructuredItems($raw['tests'] ?? [], $normalizeEvidence, $text, null, [], ['hypothesis', 'change', 'metric', 'success_condition', 'window']);

        return $this->applyOutputPreferences([
            'executive_summary' => $text($raw['executive_summary'] ?? null, 2400) ?: null,
            'observed_facts' => $claims($raw['observed_facts'] ?? []),
            'correlations' => $claims($raw['correlations'] ?? []),
            'inferences' => $claims($raw['inferences'] ?? [], true),
            'diagnoses' => $diagnoses,
            'priorities' => $priorities,
            'recommendations' => $recommendations,
            'tests' => $tests,
            'data_gaps' => collect(is_array($raw['data_gaps'] ?? null) ? $raw['data_gaps'] : [])->map(fn ($item) => $text($item, 500))->filter()->unique()->take(20)->values()->all(),
        ], $configuration);
    }

    /**
     * External sources are collected before the LLM synthesis. Prevent the
     * model from turning an already executed (or partially executed) source
     * call into a generic manual task for the tenant.
     *
     * @param array<string, mixed> $result
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function enforceExternalSourceHandling(array $result, array $snapshot): array
    {
        $sourceStatus = is_array($snapshot['source_status'] ?? null) ? $snapshot['source_status'] : [];
        $gscStatus = (string) data_get($sourceStatus, 'google_search_console.status', 'not_requested');
        $gaStatus = (string) data_get($sourceStatus, 'google_analytics.status', 'not_requested');

        $mentions = static function (mixed $value, array $needles): bool {
            if (! is_string($value) || trim($value) === '') {
                return false;
            }

            return Str::contains(Str::lower($value), $needles);
        };

        $result['data_gaps'] = collect($result['data_gaps'] ?? [])
            ->map(function (mixed $gap) use ($mentions, $gscStatus, $gaStatus): ?string {
                if (! is_string($gap) || trim($gap) === '') {
                    return null;
                }

                if ($mentions($gap, ['search console', 'search_console', 'gsc'])) {
                    return match ($gscStatus) {
                        'ready' => 'Search Console a été interrogé par Growth Advisor ; les requêtes, pages et sitemaps disponibles ont été intégrés à cette analyse.',
                        'partial' => 'Search Console a été interrogé par Growth Advisor, mais certains appels du connecteur ont échoué ; les conclusions SEO doivent rester limitées aux données retournées.',
                        'unavailable' => 'Le connecteur Search Console n’a pas fourni de données pour cette analyse ; aucune conclusion SEO détaillée ne peut être affirmée.',
                        default => $gap,
                    };
                }

                if ($mentions($gap, ['google analytics', 'google_analytics', 'ga4'])) {
                    return match ($gaStatus) {
                        'ready' => 'Google Analytics 4 a été interrogé par Growth Advisor ; les volumes, pages, sources et conversions disponibles ont été intégrés à cette analyse.',
                        'partial' => 'Google Analytics 4 a été interrogé par Growth Advisor, mais certains appels du connecteur ont échoué ; les conclusions doivent rester limitées aux données retournées.',
                        'unavailable' => 'Le connecteur Google Analytics 4 n’a pas fourni de données pour cette analyse ; aucune conclusion détaillée sur le trafic ou les conversions ne peut être affirmée.',
                        default => $gap,
                    };
                }

                return $gap;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();

        $result['recommendations'] = collect($result['recommendations'] ?? [])
            ->reject(function (mixed $recommendation) use ($mentions, $gscStatus, $gaStatus): bool {
                if (! is_array($recommendation)) {
                    return false;
                }

                $text = collect([
                    $recommendation['title'] ?? null,
                    $recommendation['what'] ?? null,
                    $recommendation['action'] ?? null,
                    $recommendation['why'] ?? null,
                ])->filter(fn (mixed $value): bool => is_string($value))->implode(' ');

                $manualGscTask = $mentions($text, [
                    'collecter les données search console',
                    'collecte des données search console',
                    'récupérer les données search console',
                    'mettre en place un suivi seo via google search console',
                ]);
                $manualGaTask = $mentions($text, [
                    'collecter les données google analytics',
                    'collecte des données google analytics',
                    'récupérer les données ga4',
                    'mettre en place un suivi google analytics',
                ]);

                return ($manualGscTask && in_array($gscStatus, ['ready', 'partial'], true))
                    || ($manualGaTask && in_array($gaStatus, ['ready', 'partial'], true));
            })
            ->values()
            ->all();

        return $result;
    }

    /**
     * Apply deterministic production safeguards after either LLM synthesis or
     * the deterministic fallback. The same contract therefore applies to
     * every execution path, including missing keys and provider failures.
     *
     * @param array<string, mixed> $result
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $visualInspection
     * @param array<int, array<string, mixed>> $visualEvidence
     * @return array<string, mixed>
     */
    private function finalizeGrowthResult(array $result, array $snapshot, array $visualInspection, array $visualEvidence): array
    {
        $result = $this->enforceExternalSourceHandling($result, $snapshot);
        $visualAnalysis = $this->visualEvidenceAnalysisPayload($snapshot, $visualEvidence, $visualInspection);
        $result = $this->enforceRecommendationEvidencePolicy($result, $visualAnalysis, $snapshot);
        $result = $this->enforceExistingElementCheck($result, $snapshot);
        $visualAnalysis['observations_cited'] = $this->countCitedVisualObservations($result, $visualAnalysis);
        $result = $this->enforceVisualGrounding($result, $visualAnalysis);

        return $result;
    }

    /**
     * Build the compact visual contract exposed to the report. Screenshots
     * remain transient; only bounded facts and counters are returned.
     *
     * @param array<string, mixed> $snapshot
     * @param array<int, array<string, mixed>> $visualEvidence
     * @param array<string, mixed> $visualInspection
     * @return array<string, mixed>
     */
    private function visualEvidenceAnalysisPayload(array $snapshot, array $visualEvidence, array $visualInspection): array
    {
        $capturesProduced = (int) data_get($snapshot, 'source_status.replay.rendered_captures', 0);
        $renderedMoments = (int) data_get($snapshot, 'source_status.replay.rendered_moments', 0);
        $visualContextRendered = (string) data_get($snapshot, 'source_status.replay.status') === 'ready'
            && $capturesProduced > 0;
        $observations = array_values(array_filter((array) ($visualInspection['observations'] ?? []), 'is_array'));
        $status = (string) ($visualInspection['status'] ?? 'unavailable');
        $error = $visualInspection['error'] ?? null;

        if ($visualContextRendered && $observations === []) {
            $status = 'incomplete';
            $error = $error ?: 'visual_observation_missing';
        }

        return [
            'tool' => $visualInspection['tool'] ?? TargetedReplayVisualInspectionTool::NAME,
            'status' => $status,
            'model' => $visualInspection['model'] ?? null,
            'visual_context_rendered' => $visualContextRendered,
            'candidate_moments' => (int) data_get($snapshot, 'source_status.replay.candidate_moments', 0),
            'rendered_moments' => $renderedMoments,
            'captures_produced' => $capturesProduced,
            'captures_sent_to_vision_tool' => count($visualEvidence),
            'observation_count' => count($observations),
            'observations_cited' => 0,
            'observations' => $observations,
            'error' => $error,
            'contract' => 'Les observations décrivent uniquement des faits visibles dans les captures ciblées. Les événements associés décrivent le comportement; aucune capture seule ne prouve un clic, une lecture, une intention ou une causalité.',
        ];
    }

    /**
     * A ready implementation plan must have a concrete crawl/replay anchor.
     * Claims about visitor behaviour additionally require an actual visual
     * observation, not merely the existence of a replay moment.
     *
     * @param array<string, mixed> $result
     * @param array<string, mixed> $visualAnalysis
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function enforceRecommendationEvidencePolicy(array $result, array $visualAnalysis, array $snapshot = []): array
    {
        $visualObservationIds = collect($visualAnalysis['observations'] ?? [])
            ->flatMap(fn (mixed $observation): array => is_array($observation)
                ? array_filter([(string) ($observation['evidence_id'] ?? ''), (string) ($observation['visual_evidence_id'] ?? '')])
                : [])
            ->unique()
            ->values()
            ->all();

        $result['recommendations'] = collect($result['recommendations'] ?? [])
            ->map(function (mixed $recommendation) use ($visualObservationIds): mixed {
                if (! is_array($recommendation)) return $recommendation;
                $plan = is_array($recommendation['implementation_plan'] ?? null)
                    ? $recommendation['implementation_plan']
                    : null;
                if (! $plan || ($plan['status'] ?? null) !== 'ready') return $recommendation;

                $evidence = $this->recommendationEvidence($recommendation);
                $hasCrawlOrReplay = collect($evidence)->contains(fn (string $id): bool =>
                    Str::startsWith($id, 'live_crawl:') || Str::startsWith($id, 'replay_visual:'));
                $hasReplayObservation = collect($evidence)->intersect($visualObservationIds)->isNotEmpty();
                $text = Str::lower(collect([
                    $recommendation['title'] ?? null,
                    $recommendation['what'] ?? null,
                    $recommendation['why'] ?? null,
                    $recommendation['how'] ?? null,
                    $recommendation['action'] ?? null,
                    $plan['desired_state'] ?? null,
                ])->filter(fn (mixed $value): bool => is_string($value))->implode(' '));
                $behavioralClaim = (bool) preg_match(
                    '/\b(visiteur|visiteurs|session|sessions|clic|clique|cliquent|voit|voient|visible|ignor|scroll|d[eé]file|pause|sortie|abandon|parcours|attention|regard|atteint|n\x27atteint|n\x27interagit)\b/iu',
                    $text,
                );
                $missing = [];
                if (! $hasCrawlOrReplay) {
                    $missing[] = 'une preuve live_crawl:* ou replay_visual:* directement reliée à la recommandation';
                }
                $locator = is_array($plan['target_locator'] ?? null) ? $plan['target_locator'] : [];
                if (in_array($plan['change_type'] ?? null, ['content', 'ux', 'ui'], true) && ! empty($locator['page_url'])) {
                    $page = collect(data_get($snapshot, 'live_crawl.pages', []))
                        ->filter(fn (mixed $candidate): bool => is_array($candidate))
                        ->first(fn (array $candidate): bool => $this->sameUrl($candidate['url'] ?? null, $locator['page_url']));
                    $pageEvidence = is_array($page) ? (string) ($page['evidence_id'] ?? '') : '';
                    if ($pageEvidence !== '' && ! in_array($pageEvidence, (array) ($locator['evidence'] ?? []), true)) {
                        $missing[] = 'la preuve live_crawl exacte de la page ciblée dans target_locator.evidence';
                    }
                }
                if ($behavioralClaim && ! $hasReplayObservation) {
                    $missing[] = 'une observation issue d’une capture replay_visual:* correspondant au comportement invoqué';
                }

                if ($missing !== []) {
                    $recommendation['implementation_plan']['status'] = 'needs_input';
                    $recommendation['implementation_plan']['limitations'] = array_values(array_unique(array_merge(
                        (array) ($recommendation['implementation_plan']['limitations'] ?? []),
                        ['Plan rétrogradé automatiquement : '.implode(' et ', $missing).'.'],
                    )));
                }

                return $recommendation;
            })
            ->values()
            ->all();

        return $result;
    }

    /**
     * Prevent a generic "add" instruction from ignoring an element already
     * present on the exact crawled page. This applies to CTA, forms, sections
     * and links, and is intentionally conservative when the target is vague.
     *
     * @param array<string, mixed> $result
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function enforceExistingElementCheck(array $result, array $snapshot): array
    {
        $pages = collect(data_get($snapshot, 'live_crawl.pages', []))
            ->filter(fn (mixed $page): bool => is_array($page) && is_string($page['url'] ?? null))
            ->values();

        $result['recommendations'] = collect($result['recommendations'] ?? [])
            ->map(function (mixed $recommendation) use ($pages): mixed {
                if (! is_array($recommendation)) return $recommendation;
                $plan = is_array($recommendation['implementation_plan'] ?? null)
                    ? $recommendation['implementation_plan']
                    : null;
                if (! $plan || ($plan['status'] ?? null) !== 'ready' || ! $this->isAdditionRequest($recommendation, $plan)) {
                    return $recommendation;
                }

                $group = $this->elementGroupForAddition($recommendation, $plan);
                if ($group === null) return $recommendation;

                $pageUrl = data_get($plan, 'target_locator.page_url');
                if (! is_string($pageUrl) || trim($pageUrl) === '') {
                    return $this->downgradeImplementationPlan($recommendation, 'La page exacte à contrôler avant l’ajout n’est pas fournie.');
                }

                $page = $pages->first(fn (array $candidate): bool => $this->sameUrl($candidate['url'] ?? null, $pageUrl));
                if (! is_array($page)) {
                    return $this->downgradeImplementationPlan($recommendation, 'La page ciblée n’apparaît pas dans live_crawl ; l’absence de l’élément ne peut pas être démontrée.');
                }

                $elements = array_values(array_filter((array) ($page[$group] ?? []), 'is_array'));
                $locator = is_array($plan['target_locator'] ?? null) ? $plan['target_locator'] : [];
                $equivalent = $this->findEquivalentElement($elements, $locator, $recommendation, $plan);
                if ($equivalent !== null) {
                    $label = $this->elementLabel($equivalent);
                    $selector = (string) ($equivalent['selector'] ?? $equivalent['selector_hint'] ?? 'sélecteur non fourni');
                    return $this->downgradeImplementationPlan(
                        $recommendation,
                        sprintf('live_crawl détecte déjà %s sur cette page : "%s" (%s). Le plan doit le reformuler, le repositionner, le renforcer ou le remplacer avant tout ajout.', $group === 'ctas' ? 'un CTA' : ($group === 'forms' ? 'un formulaire' : ($group === 'sections' ? 'une section' : 'un lien')), $label ?: 'élément sans libellé', $selector),
                    );
                }

                if ($elements !== [] && ! $this->explainsWhyExistingElementsFail($recommendation, $plan)) {
                    return $this->downgradeImplementationPlan(
                        $recommendation,
                        sprintf('live_crawl liste déjà %d élément(s) de type %s ; le rapport doit expliquer précisément pourquoi les éléments existants échouent avant de proposer un ajout.', count($elements), $group === 'ctas' ? 'CTA' : ($group === 'forms' ? 'formulaire' : ($group === 'sections' ? 'section' : 'lien'))),
                    );
                }

                // If the page contains elements of the requested type but the
                // recommendation has no exact locator/content, absence is not
                // proven. Do not let an implementation agent guess.
                if ($elements !== [] && ! ($locator['selector'] ?? null) && ! ($locator['current_text'] ?? null) && ! ($locator['current_href'] ?? null)) {
                    return $this->downgradeImplementationPlan(
                        $recommendation,
                        sprintf('live_crawl liste déjà %d élément(s) de type %s sur cette page, mais le plan ne précise pas pourquoi aucun n’est adapté ni quelle absence est démontrée.', count($elements), $group === 'ctas' ? 'CTA' : ($group === 'forms' ? 'formulaire' : ($group === 'sections' ? 'section' : 'lien'))),
                    );
                }

                $pageEvidence = (string) ($page['evidence_id'] ?? '');
                $hasPageEvidence = collect($this->recommendationEvidence($recommendation))
                    ->contains(fn (string $id): bool => $pageEvidence !== ''
                        ? $id === $pageEvidence
                        : Str::startsWith($id, 'live_crawl:'));
                if (! $hasPageEvidence) {
                    return $this->downgradeImplementationPlan($recommendation, 'L’absence de l’élément doit être explicitement étayée par la preuve live_crawl de cette page.');
                }

                return $recommendation;
            })
            ->values()
            ->all();

        return $result;
    }

    /** @param array<string, mixed> $result @param array<string, mixed> $visualAnalysis */
    private function enforceVisualGrounding(array $result, array $visualAnalysis): array
    {
        if (($visualAnalysis['visual_context_rendered'] ?? false) === true) {
            if ((int) ($visualAnalysis['observation_count'] ?? 0) === 0) {
                $result['data_gaps'][] = 'Des captures replay ont été rendues, mais aucune observation visuelle exploitable n’a été retournée ; les recommandations comportementales restent en attente de preuve.';
                $result['data_gaps'] = array_values(array_unique(array_filter($result['data_gaps'] ?? [])));
            } elseif ((int) ($visualAnalysis['observations_cited'] ?? 0) === 0) {
                $result['data_gaps'][] = 'Des observations replay sont disponibles mais aucune n’est citée dans le diagnostic final ; vérifier leur pertinence avant toute action comportementale.';
                $result['data_gaps'] = array_values(array_unique(array_filter($result['data_gaps'] ?? [])));
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $recommendation @param array<string, mixed> $plan */
    private function isAdditionRequest(array $recommendation, array $plan): bool
    {
        $text = Str::lower(collect([
            $recommendation['title'] ?? null,
            $recommendation['what'] ?? null,
            $recommendation['action'] ?? null,
            $plan['desired_state'] ?? null,
            data_get($recommendation, 'content_proposal.purpose'),
        ])->filter(fn (mixed $value): bool => is_string($value))->implode(' '));

        return (bool) preg_match('/\b(ajouter|ajout|add|create|cr[eé]er|ins[eé]rer|introduire)\b/iu', $text)
            && ! preg_match('/\b(ne pas ajouter|n\x27ajoute pas|sans ajouter)\b/iu', $text);
    }

    /** @param array<string, mixed> $recommendation @param array<string, mixed> $plan */
    private function elementGroupForAddition(array $recommendation, array $plan): ?string
    {
        $text = Str::lower(collect([
            $recommendation['title'] ?? null,
            $recommendation['what'] ?? null,
            $recommendation['where'] ?? null,
            $plan['target'] ?? null,
            $plan['desired_state'] ?? null,
            data_get($recommendation, 'content_proposal.cta'),
        ])->filter(fn (mixed $value): bool => is_string($value))->implode(' '));

        return match (true) {
            (bool) preg_match('/\b(cta|call.to.action|appel\s+[àa]\s+l.action)\b/iu', $text) => 'ctas',
            (bool) preg_match('/\b(formulaire|form|lead form)\b/iu', $text) => 'forms',
            (bool) preg_match('/\b(section|bloc|block|zone|hero|faq)\b/iu', $text) => 'sections',
            (bool) preg_match('/\b(lien|link|navigation)\b/iu', $text) => 'links',
            default => null,
        };
    }

    /** @param array<int, array<string, mixed>> $elements @param array<string, mixed> $locator @param array<string, mixed> $recommendation @param array<string, mixed> $plan */
    private function findEquivalentElement(array $elements, array $locator, array $recommendation, array $plan): ?array
    {
        $selector = Str::lower(trim((string) ($locator['selector'] ?? '')));
        $currentText = Str::lower(trim((string) ($locator['current_text'] ?? '')));
        $currentHref = trim((string) ($locator['current_href'] ?? ''));
        $desired = Str::lower(collect([
            $recommendation['title'] ?? null,
            $recommendation['what'] ?? null,
            $plan['target'] ?? null,
            $plan['desired_state'] ?? null,
            data_get($recommendation, 'content_proposal.cta'),
        ])->filter(fn (mixed $value): bool => is_string($value))->implode(' '));

        foreach ($elements as $element) {
            $elementSelector = Str::lower(trim((string) ($element['selector'] ?? $element['selector_hint'] ?? '')));
            $elementText = Str::lower(trim((string) ($element['text'] ?? $element['current_text'] ?? $element['heading'] ?? $element['label'] ?? '')));
            $elementHref = trim((string) ($element['url'] ?? $element['current_href'] ?? $element['action'] ?? ''));
            if (($selector !== '' && $elementSelector === $selector)
                || ($currentText !== '' && $elementText !== '' && $elementText === $currentText)
                || ($currentHref !== '' && $elementHref !== '' && $elementHref === $currentHref)) {
                return $element;
            }

            $keywords = collect(preg_split('/\s+/u', $desired) ?: [])
                ->map(fn (string $word): string => trim($word, " \t\n\r\0\x0B.,;:!?()[]{}\"'"))
                ->filter(fn (string $word): bool => mb_strlen($word) >= 5)
                ->take(8);
            if ($elementText !== '' && $keywords->isNotEmpty() && $keywords->filter(fn (string $word): bool => Str::contains($elementText, $word))->count() >= min(2, $keywords->count())) {
                return $element;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $recommendation */
    private function downgradeImplementationPlan(array $recommendation, string $limitation): array
    {
        $recommendation['implementation_plan']['status'] = 'needs_input';
        $recommendation['implementation_plan']['limitations'] = array_values(array_unique(array_merge(
            (array) ($recommendation['implementation_plan']['limitations'] ?? []),
            [$limitation],
        )));
        return $recommendation;
    }

    /** @param array<string, mixed> $recommendation @param array<string, mixed> $plan */
    private function explainsWhyExistingElementsFail(array $recommendation, array $plan): bool
    {
        $text = Str::lower(collect([
            $recommendation['why'] ?? null,
            $recommendation['how'] ?? null,
            $recommendation['current_state'] ?? null,
            $plan['current_state'] ?? null,
            $plan['desired_state'] ?? null,
        ])->filter(fn (mixed $value): bool => is_string($value))->implode(' '));

        return Str::contains($text, [
            'existant', 'existants', 'actuel', 'actuels', 'déjà', 'deja',
            'insuffisant', 'inadapt', 'ne convert', 'ne fonctionne',
            'trop peu visible', 'position', 'formulation', 'contraste',
            'duplique', 'doublon', 'remplace', 'reposition', 'renforce',
        ]);
    }

    /** @param array<string, mixed> $recommendation @return array<int, string> */
    private function recommendationEvidence(array $recommendation): array
    {
        return collect([
            ...((array) ($recommendation['evidence'] ?? [])),
            ...((array) data_get($recommendation, 'implementation_plan.evidence', [])),
            ...((array) data_get($recommendation, 'implementation_plan.target_locator.evidence', [])),
            ...((array) data_get($recommendation, 'content_proposal.evidence', [])),
        ])->filter(fn (mixed $id): bool => is_string($id) && $id !== '')->unique()->values()->all();
    }

    private function sameUrl(mixed $left, mixed $right): bool
    {
        if (! is_string($left) || ! is_string($right) || trim($left) === '' || trim($right) === '') return false;
        $normalize = static function (string $url): string {
            $url = trim($url);
            $parts = parse_url($url);
            if (is_array($parts) && isset($parts['host'])) {
                return Str::lower((string) $parts['host']).'/'.ltrim((string) ($parts['path'] ?? '/'), '/');
            }
            return Str::lower(ltrim($url, '/'));
        };
        return rtrim($normalize($left), '/') === rtrim($normalize($right), '/');
    }

    /** @param array<string, mixed> $element */
    private function elementLabel(array $element): string
    {
        return trim((string) ($element['text'] ?? $element['current_text'] ?? $element['heading'] ?? $element['label'] ?? $element['name'] ?? $element['action'] ?? ''));
    }

    /** @param array<string, mixed> $result @param array<string, mixed> $visualAnalysis */
    private function countCitedVisualObservations(array $result, array $visualAnalysis): int
    {
        $ids = collect($visualAnalysis['observations'] ?? [])
            ->flatMap(fn (mixed $item): array => is_array($item)
                ? array_filter([(string) ($item['evidence_id'] ?? ''), (string) ($item['visual_evidence_id'] ?? '')])
                : [])
            ->unique()
            ->all();
        if ($ids === []) return 0;

        $cited = [];
        $walk = function (mixed $value) use (&$walk, &$cited, $ids): void {
            if (is_string($value) && in_array($value, $ids, true)) {
                $cited[$value] = true;
                return;
            }
            if (is_array($value)) foreach ($value as $item) $walk($item);
        };
        $walk($result);
        return count($cited);
    }

    /** @param array<string, mixed> $result @param array<string, mixed> $configuration */
    private function applyOutputPreferences(array $result, array $configuration): array
    {
        if (($configuration['recommendation_depth'] ?? 'actionable') === 'diagnosis_only') {
            $result['recommendations'] = [];
            $result['tests'] = [];
        }

        if (($configuration['require_evidence'] ?? true) === true) {
            foreach (['observed_facts', 'correlations', 'inferences', 'diagnoses', 'priorities', 'recommendations', 'tests'] as $key) {
                $result[$key] = collect($result[$key] ?? [])
                    ->filter(fn (array $item): bool => (array) ($item['evidence'] ?? []) !== [])
                    ->values()->all();
            }
        }

        if (($configuration['allow_hypotheses'] ?? true) === false) {
            $result['diagnoses'] = collect($result['diagnoses'] ?? [])
                ->reject(fn (array $item): bool => ($item['cause_status'] ?? null) === 'hypothesis')
                ->values()->all();
            $result['inferences'] = collect($result['inferences'] ?? [])
                ->reject(fn (array $item): bool => in_array($item['evidence_strength'] ?? null, ['weak', 'insufficient'], true))
                ->values()->all();
        }

        if (($configuration['require_measurement_plan'] ?? true) === true && ($configuration['recommendation_depth'] ?? 'actionable') !== 'diagnosis_only') {
            $result['recommendations'] = collect($result['recommendations'] ?? [])
                ->filter(fn (array $item): bool => trim((string) ($item['measure'] ?? '')) !== '')
                ->values()->all();
        }

        return $result;
    }

    private function normalizeStructuredItems(array $items, callable $normalizeEvidence, callable $text, ?string $enumKey, array $enumValues, array $extraKeys = []): array
    {
        return collect($items)->map(function ($item) use ($normalizeEvidence, $text, $enumKey, $enumValues, $extraKeys): ?array {
            if (! is_array($item)) return null;
            $title = $text($item['title'] ?? $item['text'] ?? $item['hypothesis'] ?? null, 500);
            if (! $title) return null;
            $out = [
                'id' => $text($item['id'] ?? null, 100) ?: 'item:'.substr(sha1($title), 0, 12),
                'title' => $title,
                'evidence' => $normalizeEvidence($item['evidence'] ?? []),
            ];
            if ($enumKey) $out[$enumKey] = in_array($item[$enumKey] ?? null, $enumValues, true) ? $item[$enumKey] : 'medium';
            foreach ($extraKeys as $key) $out[$key] = $text($item[$key] ?? null, 1000);
            if (isset($item['why'])) $out['why'] = $text($item['why'], 1000);
            return $out;
        })->filter()->values()->take(30)->all();
    }

    /** @param array<int, mixed> $items @param array<int, string> $validEvidenceIds */
    private function normalizeRecommendations(array $items, callable $normalizeEvidence, callable $text, array $configuration = [], array $knowledgeEvidence = []): array
    {
        $normalized = $this->normalizeStructuredItems($items, $normalizeEvidence, $text, 'priority', ['high', 'medium', 'low'], [
            'action', 'basis', 'what', 'where', 'why', 'how', 'expected_effect', 'measure', 'verification_needed',
        ]);

        return collect($normalized)->map(function (array $recommendation) use ($items, $normalizeEvidence, $text, $configuration, $knowledgeEvidence): array {
            $raw = collect($items)->first(fn ($item): bool => is_array($item)
                && ((string) ($item['id'] ?? '') === (string) $recommendation['id']
                    || (string) ($item['title'] ?? $item['text'] ?? '') === (string) ($recommendation['title'] ?? '')));
            $recommendation['implementation_plan'] = $this->normalizeImplementationPlan(
                is_array($raw) ? ($raw['implementation_plan'] ?? null) : null,
                $normalizeEvidence,
                $text,
            );
            $proposal = is_array($raw) ? ($raw['content_proposal'] ?? null) : null;
            $recommendation['content_proposal'] = ($configuration['knowledge_options']['use_knowledge_for_recommendations'] ?? true) === true
                ? $this->normalizeContentProposal($proposal, $normalizeEvidence, $text, $knowledgeEvidence)
                : null;
            return $recommendation;
        })->values()->all();
    }

    private function normalizeImplementationPlan(mixed $plan, callable $normalizeEvidence, callable $text): array
    {
        $validStatuses = ['ready', 'needs_input', 'not_applicable'];
        $validChangeTypes = ['content', 'ux', 'ui', 'tracking', 'seo', 'technical', 'configuration', 'workflow', 'integration', 'other'];

        if (! is_array($plan)) {
            return [
                'status' => 'needs_input',
                'change_type' => 'other',
                'target' => null,
                'target_locator' => null,
                'operation' => null,
                'current_state' => null,
                'desired_state' => null,
                'steps' => [],
                'parameters' => [],
                'files_or_resources' => [],
                'dependencies' => [],
                'acceptance_criteria' => [],
                'rollback' => null,
                'evidence' => [],
                'limitations' => ['Le plan d’implémentation structuré n’a pas été fourni par la synthèse.'],
            ];
        }

        $status = in_array($plan['status'] ?? null, $validStatuses, true) ? $plan['status'] : 'needs_input';
        $changeType = in_array($plan['change_type'] ?? null, $validChangeTypes, true) ? $plan['change_type'] : 'other';
        $list = static function (mixed $value, callable $text, int $limit = 10): array {
            return collect(is_array($value) ? $value : [])
                ->map(fn ($item) => $text($item, 600))
                ->filter()
                ->unique()
                ->take($limit)
                ->values()
                ->all();
        };
        $parameters = collect(is_array($plan['parameters'] ?? null) ? $plan['parameters'] : [])
            ->map(function ($parameter) use ($text): ?array {
                if (is_array($parameter)) {
                    $name = $text($parameter['name'] ?? null, 200);
                    $value = $text($parameter['value'] ?? null, 800);
                } else {
                    $name = null;
                    $value = $text($parameter, 800);
                }

                return $name && $value ? ['name' => $name, 'value' => $value] : null;
            })
            ->filter()
            ->take(20)
            ->values()
            ->all();
        $limitations = $list($plan['limitations'] ?? [], $text, 10);
        $normalized = [
            'status' => $status,
            'change_type' => $changeType,
            'target' => $text($plan['target'] ?? null, 800),
            'target_locator' => $this->normalizeTargetLocator($plan['target_locator'] ?? null, $normalizeEvidence, $text),
            'operation' => $text($plan['operation'] ?? data_get($plan, 'target_locator.operation'), 300),
            'current_state' => $text($plan['current_state'] ?? null, 1000),
            'desired_state' => $text($plan['desired_state'] ?? null, 1000),
            'steps' => $list($plan['steps'] ?? [], $text, 20),
            'parameters' => $parameters,
            'files_or_resources' => $list($plan['files_or_resources'] ?? [], $text, 20),
            'dependencies' => $list($plan['dependencies'] ?? [], $text, 20),
            'acceptance_criteria' => $list($plan['acceptance_criteria'] ?? [], $text, 20),
            'rollback' => $text($plan['rollback'] ?? null, 1000),
            'evidence' => $normalizeEvidence($plan['evidence'] ?? []),
            'limitations' => $limitations,
        ];

        if ($status === 'ready') {
            $missing = [];
            if (! $normalized['target']) $missing[] = 'la cible exacte';
            if (! $normalized['operation']) $missing[] = 'l’opération à exécuter';
            if (! $normalized['current_state']) $missing[] = 'l’état actuel';
            if (! $normalized['desired_state']) $missing[] = 'l’état attendu';
            if ($normalized['parameters'] === []) $missing[] = 'les paramètres concrets';
            if ($normalized['steps'] === []) $missing[] = 'les étapes d’implémentation';
            if ($normalized['acceptance_criteria'] === []) $missing[] = 'les critères d’acceptation';
            if (! $normalized['rollback']) $missing[] = 'le rollback';
            if ($normalized['evidence'] === []) $missing[] = 'une preuve reliée';

            $domChangeTypes = ['content', 'ux', 'ui'];
            if (in_array($changeType, $domChangeTypes, true)) {
                $locator = $normalized['target_locator'];
                if (! is_array($locator)) {
                    $missing[] = 'le locator DOM exact (page_url, selector et element_type)';
                } else {
                    foreach (['page_url', 'selector', 'element_type'] as $key) {
                        if (! $locator[$key]) $missing[] = 'target_locator.'.$key;
                    }
                    if (! ($locator['current_text'] || $locator['current_href'] || $locator['element_type'] === 'form')) {
                        $missing[] = 'target_locator.current_text ou current_href';
                    }
                    if ($locator['operation'] !== $normalized['operation']) {
                        $missing[] = 'une opération identique dans target_locator.operation';
                    }
                    if (! collect($locator['evidence'])->contains(fn (string $id): bool => Str::startsWith($id, 'live_crawl:'))) {
                        $missing[] = 'une preuve live_crawl du locator DOM';
                    }
                }
            }
            if ($missing !== []) {
                $normalized['status'] = 'needs_input';
                $normalized['limitations'][] = 'Plan non exécutable sans : '.implode(', ', $missing).'.';
            }
        }

        return $normalized;
    }

    /** @return array<string, mixed>|null */
    private function normalizeTargetLocator(mixed $locator, callable $normalizeEvidence, callable $text): ?array
    {
        if (! is_array($locator)) return null;

        return [
            'page_url' => $text($locator['page_url'] ?? null, 800),
            'selector' => $text($locator['selector'] ?? null, 800),
            'element_type' => $text($locator['element_type'] ?? null, 120),
            'current_text' => $text($locator['current_text'] ?? null, 800),
            'current_href' => $text($locator['current_href'] ?? null, 800),
            'operation' => $text($locator['operation'] ?? null, 300),
            'evidence' => $normalizeEvidence($locator['evidence'] ?? []),
        ];
    }

    private function normalizeContentProposal(mixed $proposal, callable $normalizeEvidence, callable $text, array $knowledgeEvidence = []): ?array
    {
        if (! is_array($proposal)) return null;
        $status = in_array($proposal['status'] ?? null, ['ready', 'insufficient_evidence', 'not_applicable'], true)
            ? $proposal['status']
            : 'insufficient_evidence';
        $evidence = $normalizeEvidence($proposal['evidence'] ?? []);
        $qualifiedKnowledgeEvidence = collect($evidence)
            ->filter(function (string $id) use ($knowledgeEvidence): bool {
                $item = is_array($knowledgeEvidence[$id] ?? null) ? $knowledgeEvidence[$id] : [];
                return Str::startsWith($id, 'knowledge:')
                    && ($item['purpose'] ?? null) === 'content_proposal'
                    && ($item['status'] ?? null) === 'ready'
                    && ! empty($item['results']);
            })
            ->values()->all();
        $content = [
            'status' => $status,
            'purpose' => $text($proposal['purpose'] ?? null, 500),
            'placement' => $text($proposal['placement'] ?? null, 600),
            'title' => $text($proposal['title'] ?? null, 300),
            'body' => $text($proposal['body'] ?? null, 2500),
            'bullets' => collect(is_array($proposal['bullets'] ?? null) ? $proposal['bullets'] : [])
                ->map(fn ($item) => $text($item, 400))->filter()->take(8)->values()->all(),
            'faq' => collect(is_array($proposal['faq'] ?? null) ? $proposal['faq'] : [])
                ->map(function ($item) use ($text): ?array {
                    if (! is_array($item)) return null;
                    $question = $text($item['question'] ?? null, 300);
                    $answer = $text($item['answer'] ?? null, 900);
                    return $question && $answer ? ['question' => $question, 'answer' => $answer] : null;
            })->filter()->take(8)->values()->all(),
            'cta' => $text($proposal['cta'] ?? null, 300),
            'cta_destination' => $text($proposal['cta_destination'] ?? null, 500),
            'cta_why' => $text($proposal['cta_why'] ?? null, 800),
            'evidence' => $qualifiedKnowledgeEvidence,
            'limitations' => collect(is_array($proposal['limitations'] ?? null) ? $proposal['limitations'] : [])
                ->map(fn ($item) => $text($item, 400))->filter()->take(8)->values()->all(),
        ];

        // A content draft is only allowed when the model cites a real
        // Knowledge evidence item. Otherwise retain the useful structure but
        // remove ungrounded copy from the persisted result.
        if ($status === 'ready' && $qualifiedKnowledgeEvidence === []) {
            $content['status'] = 'insufficient_evidence';
        }

        if ($content['status'] !== 'ready') {
            $content['title'] = null;
            $content['body'] = null;
            $content['bullets'] = [];
            $content['faq'] = [];
            $content['cta'] = null;
            $content['cta_destination'] = null;
            $content['cta_why'] = null;
            if ($qualifiedKnowledgeEvidence === []) {
                $content['limitations'][] = 'Le texte proposé est masqué : aucun résultat Knowledge content_proposal prêt et non vide ne confirme cette formulation.';
            }
        }

        return $content;
    }

    private function selectRepresentativeSessions(Collection $sessions, int $limit = self::MAX_REPRESENTATIVE_SESSIONS): Collection
    {
        $limit = max(1, $limit);
        $ranked = $sessions
            ->sortByDesc(fn (VisitorSession $session): int => $this->representativeScore($session))
            ->values();

        // Preserve the strongest business signals, while ensuring that a
        // dominant source (for example direct traffic) does not hide all the
        // other acquisition journeys from the diagnostic.
        $selected = collect();
        $seenSources = [];
        foreach ($ranked as $session) {
            if ($selected->count() >= $limit) break;
            $sourceKey = $this->sessionAcquisitionBucketKey($session);
            if ($sourceKey === 'unknown' || isset($seenSources[$sourceKey])) continue;
            $selected->push($session);
            $seenSources[$sourceKey] = true;
        }

        foreach ($ranked as $session) {
            if ($selected->count() >= $limit) break;
            if (! $selected->contains('id', $session->id)) $selected->push($session);
        }

        return $selected->values();
    }

    /**
     * Select only journeys that can actually produce visual evidence.
     *
     * The representative pool intentionally contains business-significant
     * sessions even when their replay has expired or was never recorded. The
     * replayable backfill is appended after that pool, so filtering on the
     * chunk count must happen before applying the visual-session limit.
     *
     * @param array<string, array<string, mixed>> $sessionAnalyses
     * @return array<int, string>
     */
    private function selectVisualSessionIds(Collection $sessions, array $sessionAnalyses, int $limit): array
    {
        return $sessions
            ->filter(fn (VisitorSession $session): bool => (int) ($session->replay_chunks_count ?? 0) > 0)
            ->filter(function (VisitorSession $session) use ($sessionAnalyses): bool {
                return collect($sessionAnalyses[(string) $session->id]['moments'] ?? [])
                    ->contains(fn (array $moment): bool => (bool) ($moment['needs_visual_context'] ?? false) || (bool) ($moment['capture_candidate'] ?? false));
            })
            ->take(max(0, $limit))
            ->map(fn (VisitorSession $session): string => (string) $session->id)
            ->values()
            ->all();
    }

    private function representativeScore(VisitorSession $session): int
    {
        $summary = $session->summary;
        $score = 0;
        if ($session->converted) $score += 100;
        if ($session->has_widget_interaction) $score += 30;
        if ($session->intent_level === 'high') $score += 60;
        if ($summary && count((array) $summary->friction_points) > 0) $score += 45;
        if ($session->ended_at && ! $session->converted) $score += 20;
        // Prefer journeys that can actually be investigated visually, but
        // keep the business-signal score dominant so visual evidence stays
        // tied to meaningful conversion or abandonment patterns.
        if ((int) ($session->replay_chunks_count ?? 0) > 0) $score += 35;
        if (! $session->converted && (int) $session->page_count >= 2) $score += 20;
        return $score + min(20, (int) $session->page_count);
    }

    private function sessionAcquisitionKey(VisitorSession $session): string
    {
        $acquisition = $this->acquisitionValues($session);
        $parts = [
            trim(Str::lower((string) ($acquisition['source_type'] ?: 'unknown'))),
            trim(Str::lower((string) ($acquisition['source'] ?: 'unknown'))),
            trim(Str::lower((string) ($acquisition['medium'] ?: 'unknown'))),
        ];

        return implode('|', array_map(fn (string $part): string => $part !== '' ? $part : 'unknown', $parts));
    }

    private function sessionAcquisitionBucketKey(VisitorSession $session): string
    {
        $acquisition = $this->acquisitionValues($session);
        $sourceType = trim(Str::lower((string) ($acquisition['source_type'] ?: '')));
        if ($sourceType !== '') return $sourceType;

        $source = trim(Str::lower((string) ($acquisition['source'] ?: '')));
        $medium = trim(Str::lower((string) ($acquisition['medium'] ?: '')));
        return $source !== '' ? $source : ($medium !== '' ? $medium : 'unknown');
    }

    /**
     * Resolve both current normalized attribution columns and the metadata
     * projection used by historical Visitor Intelligence sessions. This is
     * intentionally read-only: old sessions must become analyzable without a
     * migration or a second browser visit.
     *
     * @return array<string, mixed>
     */
    private function acquisitionValues(VisitorSession $session): array
    {
        $metadata = is_array($session->metadata) ? $session->metadata : [];
        $attribution = is_array(data_get($metadata, 'attribution'))
            ? data_get($metadata, 'attribution')
            : [];
        $legacySource = trim((string) ($session->source ?? ''));
        $metadataSource = data_get($metadata, 'source');

        return [
            'source_type' => $session->acquisition_source_type ?: ($attribution['source_type'] ?? null),
            'source' => $session->acquisition_source
                ?: ($attribution['source'] ?? null)
                ?: ($legacySource !== 'website' ? $legacySource : $metadataSource),
            'medium' => $session->acquisition_medium ?: ($attribution['medium'] ?? null),
            'campaign' => $session->acquisition_campaign ?: ($attribution['campaign'] ?? null),
            'term' => $session->acquisition_term ?: ($attribution['term'] ?? null),
            'content' => $session->acquisition_content ?: ($attribution['content'] ?? null),
            'platform' => $session->acquisition_platform ?: ($attribution['platform'] ?? null),
            'referrer' => $session->acquisition_referrer ?: ($attribution['referrer'] ?? null),
            'confidence' => $session->acquisition_confidence ?: ($attribution['confidence'] ?? null),
        ];
    }

    /** @return array<string, mixed> */
    private function sessionAcquisitionContext(VisitorSession $session, array &$evidence): array
    {
        $acquisition = $this->acquisitionValues($session);
        $evidenceId = 'visitor_acquisition:session:'.substr(sha1((string) $session->id), 0, 12);
        $context = [
            'evidence_id' => $evidenceId,
            'source_type' => $this->safeText($acquisition['source_type'] ?? null, 64) ?: 'unknown',
            'source' => $this->safeText($acquisition['source'] ?? null, 128) ?: 'unknown',
            'medium' => $this->safeText($acquisition['medium'] ?? null, 64),
            'campaign' => $this->safeText($acquisition['campaign'] ?? null, 255),
            'term' => $this->safeText($acquisition['term'] ?? null, 255),
            'content' => $this->safeText($acquisition['content'] ?? null, 255),
            'platform' => $this->safeText($acquisition['platform'] ?? null, 64),
            'referrer' => $this->safeText($acquisition['referrer'] ?? null, 500),
            'confidence' => $this->safeText($acquisition['confidence'] ?? null, 32),
        ];
        $this->registerEvidence($evidence, $evidenceId, 'visitor_acquisition', 'Source d’acquisition du parcours', [
            'session_id' => (string) $session->id,
            ...$context,
        ]);

        return $context;
    }

    /** @return array<int, array<string, mixed>> */
    private function acquisitionJourneyPatterns(Collection $sessions, array &$evidence): array
    {
        return $sessions
            ->groupBy(fn (VisitorSession $session): string => $this->sessionAcquisitionKey($session))
            ->map(function (Collection $group, string $sourceKey) use (&$evidence): array {
                /** @var VisitorSession $first */
                $first = $group->first();
                $firstAcquisition = $this->acquisitionValues($first);
                $acquisitions = $group->map(fn (VisitorSession $session): array => $this->acquisitionValues($session));
                $sessionsCount = $group->count();
                $conversions = $group->filter(fn (VisitorSession $session): bool => (bool) $session->converted)->count();
                $durations = $group->filter(fn (VisitorSession $session): bool => is_numeric($session->duration_seconds));
                $evidenceId = 'visitor_acquisition_pattern:'.substr(sha1($sourceKey), 0, 12);
                $pattern = [
                    'evidence_id' => $evidenceId,
                    'source_type' => $this->safeText($firstAcquisition['source_type'] ?? null, 64) ?: 'unknown',
                    'source' => $this->safeText($firstAcquisition['source'] ?? null, 128) ?: 'unknown',
                    'medium' => $this->safeText($firstAcquisition['medium'] ?? null, 64),
                    'campaigns' => $acquisitions->pluck('campaign')->filter()->unique()->take(10)->values()->all(),
                    'sessions' => $sessionsCount,
                    'conversions' => $conversions,
                    'conversion_rate' => $sessionsCount > 0 ? round($conversions / $sessionsCount * 100, 2) : 0,
                    // A completed non-converting session is not proof of an
                    // abandonment. Keep the metric explicit and neutral.
                    'non_converted_sessions' => $group->filter(fn (VisitorSession $session): bool => $session->ended_at !== null && ! $session->converted)->count(),
                    'high_intent_sessions' => $group->filter(fn (VisitorSession $session): bool => $session->intent_level === 'high')->count(),
                    'widget_interaction_sessions' => $group->filter(fn (VisitorSession $session): bool => (bool) $session->has_widget_interaction)->count(),
                    'average_page_count' => round((float) $group->avg(fn (VisitorSession $session): float => (float) $session->page_count), 2),
                    'average_duration_seconds' => $durations->isEmpty()
                        ? null
                        : round((float) $durations->avg(fn (VisitorSession $session): float => (float) $session->duration_seconds), 2),
                    'top_entry_pages' => $group->pluck('entry_url')->filter()->map(fn ($url) => $this->safeText($url, 500))->filter()->countBy()->sortDesc()->take(5)->keys()->values()->all(),
                    'top_exit_pages' => $group->pluck('exit_url')->filter()->map(fn ($url) => $this->safeText($url, 500))->filter()->countBy()->sortDesc()->take(5)->keys()->values()->all(),
                ];
                $this->registerEvidence($evidence, $evidenceId, 'visitor_acquisition_pattern', 'Comparaison comportementale par source', $pattern);

                return $pattern;
            })
            ->sortByDesc('sessions')
            ->take(20)
            ->values()
            ->all();
    }

    /**
     * Ensure source-group facts survive LLM synthesis. These facts are
     * deterministic, scoped to the analyzed period and linked to the exact
     * acquisition-pattern evidence used to compute them.
     *
     * @param array<string, mixed> $result
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function ensureAcquisitionCoverage(array $result, array $snapshot): array
    {
        $patterns = collect($snapshot['acquisition_journeys'] ?? [])
            ->filter(fn (mixed $pattern): bool => is_array($pattern) && is_string($pattern['evidence_id'] ?? null))
            ->values();
        if ($patterns->isEmpty()) return $result;

        $covered = collect($result['observed_facts'] ?? [])
            ->flatMap(fn (mixed $fact): array => is_array($fact) ? (array) ($fact['evidence'] ?? []) : [])
            ->filter(fn (mixed $id): bool => is_string($id))
            ->flip();

        foreach ($patterns->take(20) as $pattern) {
            $evidenceId = (string) $pattern['evidence_id'];
            if ($covered->has($evidenceId)) continue;

            $sourceType = $this->safeText($pattern['source_type'] ?? null, 64) ?: 'source inconnue';
            $source = $this->safeText($pattern['source'] ?? null, 128);
            $medium = $this->safeText($pattern['medium'] ?? null, 64);
            $label = trim(implode(' · ', array_filter([$sourceType, $source, $medium])));
            $entryPages = array_values(array_filter((array) ($pattern['top_entry_pages'] ?? [])));
            $exitPages = array_values(array_filter((array) ($pattern['top_exit_pages'] ?? [])));
            $text = sprintf(
                'Les parcours attribués à %s représentent %d session(s), %d conversion(s), un taux de conversion observé de %.2f %% et %d session(s) terminée(s) sans conversion observée. Entrées principales : %s. Sorties principales : %s.',
                $label !== '' ? $label : 'une source inconnue',
                (int) ($pattern['sessions'] ?? 0),
                (int) ($pattern['conversions'] ?? 0),
                (float) ($pattern['conversion_rate'] ?? 0),
                (int) ($pattern['non_converted_sessions'] ?? $pattern['abandoned_sessions'] ?? 0),
                $entryPages !== [] ? implode(', ', array_slice($entryPages, 0, 3)) : 'non disponibles',
                $exitPages !== [] ? implode(', ', array_slice($exitPages, 0, 3)) : 'non disponibles',
            );

            $result['observed_facts'][] = [
                'id' => 'fact:acquisition:'.substr(sha1($evidenceId), 0, 12),
                'text' => $text,
                'evidence' => [$evidenceId],
            ];
            $covered->put($evidenceId, true);
        }

        $result['observed_facts'] = array_values($result['observed_facts'] ?? []);
        return $result;
    }

    private function messagesByConversation(string $siteId, Collection $conversationIds): Collection
    {
        $messages = collect();
        foreach ($conversationIds as $conversationId) {
            $messages[$conversationId] = Message::query()
                ->without('attachment')
                ->where('conversation_id', $conversationId)
                ->whereHas('conversation', fn ($query) => $query->where('site_id', $siteId))
                ->reorder('created_at', 'asc')
                ->limit(20)
                ->get(['id', 'role', 'content', 'created_at']);
        }
        return $messages;
    }

    private function eventContext(Collection $events, array &$evidence): array
    {
        return $events->reject(fn (AnalyticsEvent $event): bool => $event->event_type === 'pointer_move')
            ->take(80)
            ->map(function (AnalyticsEvent $event) use (&$evidence): array {
                $id = 'event:'.(string) $event->id;
                $metadata = is_array($event->metadata) ? $event->metadata : [];
                $this->registerEvidence($evidence, $id, 'visitor_event', (string) $event->event_type, [
                    'event_id' => (string) $event->id,
                    'session_id' => $event->session_id,
                    'occurred_at' => $event->occurred_at?->toISOString(),
                ]);
                return [
                    'evidence_id' => $id,
                    'event_id' => (string) $event->id,
                    'event_type' => (string) $event->event_type,
                    'occurred_at' => $event->occurred_at?->toISOString(),
                    'path' => $this->safeText(data_get($metadata, 'path'), 400),
                    'page_url' => $this->safeText(data_get($metadata, 'page_url'), 500),
                    'label' => $this->safeText($event->label, 400),
                    'resource_type' => $event->resource_type,
                    'resource_id' => $event->resource_id,
                    'conversation_id' => $event->conversation_id,
                ];
            })->values()->all();
    }

    private function conversationContext(Collection $events, Collection $conversations, Collection $messagesByConversation, array &$evidence): array
    {
        return $events->pluck('conversation_id')->filter()->unique()->map(function ($id) use ($conversations, $messagesByConversation, &$evidence): ?array {
            $conversation = $conversations->get((string) $id);
            if (! $conversation) return null;
            $conversationEvidenceId = 'conversation:'.(string) $conversation->id;
            $messages = collect($messagesByConversation->get($id, []))->map(function (Message $message) use (&$evidence, $conversation): array {
                $messageEvidenceId = 'conversation_message:'.(string) $message->id;
                $this->registerEvidence($evidence, $messageEvidenceId, 'conversation_message', 'Message de conversation', [
                    'conversation_id' => (string) $conversation->id,
                    'message_id' => (string) $message->id,
                    'created_at' => $message->created_at?->toISOString(),
                ]);
                return [
                    'evidence_id' => $messageEvidenceId,
                    'message_id' => (string) $message->id,
                    'role' => $message->role,
                    'content' => $this->safeText($message->content, 1200),
                    'created_at' => $message->created_at?->toISOString(),
                ];
            })->values();
            $this->registerEvidence($evidence, $conversationEvidenceId, 'conversation', 'Conversation liée au parcours', [
                'conversation_id' => (string) $conversation->id,
                'status' => $conversation->status,
            ]);
            return [
                'conversation_id' => (string) $conversation->id,
                'evidence_id' => $conversationEvidenceId,
                'status' => $conversation->status,
                'summary' => $this->safeText($conversation->summary, 1000),
                'messages' => $messages->all(),
                'signals' => $this->conversationSignals($messages, (string) $conversation->id),
            ];
        })->filter()->values()->all();
    }

    private function conversationSignals(Collection $messages, string $conversationId): array
    {
        $signals = [];
        foreach ($messages as $message) {
            if (($message['role'] ?? null) !== 'user' || ! is_string($message['content'] ?? null)) continue;
            $content = $message['content'];
            $patterns = [
                'pricing' => '/\b(prix|tarif|co[uû]t|budget|devis|quote)\b/iu',
                'objection_or_friction' => '/\b(cher|trop long|ne comprend|probl[eè]me|difficile|bloqu|erreur|ne fonctionne)\b/iu',
                'clarification' => '/\?|\b(comment|quel(?:le)?|o[uù]|quand|combien|pourquoi)\b/iu',
                'knowledge_gap' => '/\b(information|d[eé]tail|disponib|fonctionne|service|produit)\b/iu',
            ];
            foreach ($patterns as $type => $pattern) {
                if (! preg_match($pattern, $content)) continue;
                $signals[] = [
                    'type' => $type,
                    'text' => Str::limit($content, 500, '…'),
                    'message_id' => $message['message_id'],
                    'conversation_id' => $conversationId,
                    'created_at' => $message['created_at'],
                    'evidence_id' => $message['evidence_id'],
                ];
            }
        }
        return collect($signals)->unique(fn (array $signal): string => $signal['type'].'|'.$signal['message_id'])->take(20)->values()->all();
    }

    /** @param array<int, mixed> $conversations @param array<string, mixed> $options */
    private function applyConversationOptions(array $conversations, array $options): array
    {
        $allowed = [];
        if (($options['detect_questions'] ?? true) === true) $allowed[] = 'clarification';
        if (($options['detect_objections'] ?? true) === true) $allowed[] = 'objection_or_friction';
        if (($options['detect_knowledge_gaps'] ?? true) === true) $allowed[] = 'knowledge_gap';
        if (($options['detect_conversion_intent'] ?? true) === true) $allowed[] = 'pricing';

        return collect($conversations)->map(function (array $conversation) use ($allowed): array {
            $conversation['signals'] = collect($conversation['signals'] ?? [])
                ->filter(fn (array $signal): bool => in_array($signal['type'] ?? null, $allowed, true))
                ->values()->all();
            return $conversation;
        })->values()->all();
    }

    private function journeyContext(Collection $events): array
    {
        $pageSequence = $events
            ->filter(fn (AnalyticsEvent $event): bool => in_array((string) $event->event_type, ['page_view', 'navigation'], true))
            ->map(function (AnalyticsEvent $event): ?array {
                $metadata = is_array($event->metadata) ? $event->metadata : [];
                $path = $this->safeText(data_get($metadata, 'path'), 400)
                    ?: $this->safeText(data_get($metadata, 'page_url'), 500)
                    ?: $this->safeText($event->label, 400);
                if (! $path) return null;
                return [
                    'evidence_id' => 'event:'.(string) $event->id,
                    'event_id' => (string) $event->id,
                    'path' => $path,
                    'occurred_at' => $event->occurred_at?->toISOString(),
                ];
            })->filter()->take(40)->values();
        $criticalEvents = $events
            ->reject(fn (AnalyticsEvent $event): bool => in_array((string) $event->event_type, ['pointer_move', 'page_view', 'navigation'], true))
            ->map(fn (AnalyticsEvent $event): array => [
                'evidence_id' => 'event:'.(string) $event->id,
                'event_id' => (string) $event->id,
                'event_type' => (string) $event->event_type,
                'label' => $this->safeText($event->label, 300),
                'occurred_at' => $event->occurred_at?->toISOString(),
            ])->take(40)->values();
        return [
            'page_sequence' => $pageSequence->all(),
            'unique_pages' => $pageSequence->pluck('path')->filter()->unique()->count(),
            'critical_events' => $criticalEvents->all(),
        ];
    }

    private function summaryContext($summary): ?array
    {
        if (! $summary) return null;
        return [
            'summary' => $this->safeText($summary->summary, 1200),
            'intent_level' => $summary->intent_level,
            'probable_goal' => $this->safeText($summary->probable_goal, 300),
            'probable_outcome' => $summary->probable_outcome,
            'friction_points' => array_slice((array) $summary->friction_points, 0, 10),
            'purchase_signals' => array_slice((array) $summary->purchase_signals, 0, 10),
            'unresolved_questions' => array_slice((array) $summary->unresolved_questions, 0, 10),
            'evidence' => array_slice((array) $summary->evidence, 0, 20),
        ];
    }

    private function annotateMoments(array $moments, string $sessionId, array &$evidence): array
    {
        return collect($moments)->map(function (array $moment) use ($sessionId, &$evidence): array {
            $momentId = (string) ($moment['id'] ?? 'moment:'.substr(sha1(json_encode($moment)), 0, 12));
            $evidenceId = 'replay:'.$sessionId.':'.$momentId;
            $this->registerEvidence($evidence, $evidenceId, 'replay_moment', 'Moment comportemental détecté', [
                'session_id' => $sessionId,
                'moment_id' => $momentId,
                'event_ids' => array_values($moment['event_ids'] ?? []),
                'occurred_at' => $moment['occurred_at'] ?? null,
                'path' => $moment['path'] ?? null,
                'reason' => $moment['reason'] ?? null,
            ]);
            return [
                ...$moment,
                'id' => $momentId,
                'evidence_id' => $evidenceId,
            ];
        })->values()->all();
    }

    private function visualContext(array $visual, string $sessionId, array &$evidence, bool $includeCaptures = false): array
    {
        return [
            'available' => (bool) ($visual['available'] ?? false),
            'error' => $visual['error'] ?? null,
            'moments' => collect($visual['moments'] ?? [])->map(function (array $moment) use ($sessionId, &$evidence, $includeCaptures): array {
                $momentId = (string) ($moment['id'] ?? $moment['moment_id'] ?? 'visual:'.substr(sha1(json_encode($moment)), 0, 12));
                $evidenceId = 'replay_visual:'.$sessionId.':'.$momentId;
                $this->registerEvidence($evidence, $evidenceId, 'replay_visual', 'Contexte visuel rrweb rendu', [
                    'session_id' => $sessionId,
                    'moment_id' => $momentId,
                    'capture_available' => ! empty($moment['capture']),
                ]);
                $context = [
                    'evidence_id' => $evidenceId,
                    'moment_id' => $momentId,
                    'event_ids' => array_values($moment['event_ids'] ?? []),
                    'event_types' => array_values($moment['event_types'] ?? []),
                    'reason' => $moment['reason'] ?? null,
                    'replay_timestamp' => $moment['replay_timestamp'] ?? null,
                    'relative_timestamp' => $moment['relative_timestamp'] ?? null,
                    'pointer_moves_since_previous' => max(0, (int) ($moment['pointer_moves_since_previous'] ?? 0)),
                    'event_context' => array_slice((array) ($moment['event_context'] ?? []), 0, 12),
                    'page' => $moment['page'] ?? null,
                    'scroll' => $moment['scroll'] ?? null,
                    'visible_text' => $this->safeText($moment['visible_text'] ?? null, 1200),
                    'visible_elements' => array_slice((array) ($moment['visible_elements'] ?? []), 0, 20),
                    'capture_available' => ! empty($moment['capture']),
                ];

                // The capture is deliberately attached only to the transient
                // in-memory snapshot used by composeResult(). It is stripped
                // before data_snapshot is persisted.
                if ($includeCaptures && ! empty($moment['capture'])) {
                    $context['capture'] = (string) $moment['capture'];
                }

                return $context;
            })->values()->all(),
        ];
    }

    /**
     * Remove transient screenshots before a snapshot is persisted or exposed
     * through a deterministic snapshot tool.
     *
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function withoutVisualCaptures(array $snapshot): array
    {
        $persisted = $snapshot;
        foreach (($persisted['representative_sessions'] ?? []) as $sessionIndex => $session) {
            if (! is_array($session)) continue;
            foreach (data_get($session, 'visual_context.moments', []) as $momentIndex => $moment) {
                if (! is_array($moment)) continue;
                unset($persisted['representative_sessions'][$sessionIndex]['visual_context']['moments'][$momentIndex]['capture']);
            }
        }

        return $persisted;
    }

    /**
     * Extract only the bounded screenshots produced by the targeted replay
     * investigation. No rrweb chunk, DOM serialization or replay event is
     * included here.
     *
     * @param array<string, mixed> $snapshot
     * @return array<int, array<string, mixed>>
     */
    private function visualEvidenceForLlm(array $snapshot): array
    {
        $evidence = [];
        foreach (($snapshot['representative_sessions'] ?? []) as $session) {
            if (! is_array($session)) continue;
            $sessionId = (string) ($session['session_id'] ?? '');
            foreach (data_get($session, 'visual_context.moments', []) as $moment) {
                if (! is_array($moment) || empty($moment['capture'])) continue;
                $evidence[] = [
                    'evidence_id' => (string) ($moment['evidence_id'] ?? ''),
                    'session_id' => $sessionId,
                    'moment_id' => (string) ($moment['moment_id'] ?? ''),
                    'reason' => $moment['reason'] ?? null,
                    'replay_timestamp' => $moment['replay_timestamp'] ?? null,
                    'event_ids' => array_values((array) ($moment['event_ids'] ?? [])),
                    'event_types' => array_values((array) ($moment['event_types'] ?? [])),
                    'pointer_moves_since_previous' => max(0, (int) ($moment['pointer_moves_since_previous'] ?? 0)),
                    'event_context' => array_slice((array) ($moment['event_context'] ?? []), 0, 12),
                    'page' => $moment['page'] ?? null,
                    'scroll' => $moment['scroll'] ?? null,
                    'visible_text' => $this->safeText($moment['visible_text'] ?? null, 1200),
                    'visible_elements' => array_slice((array) ($moment['visible_elements'] ?? []), 0, 20),
                    'capture' => (string) $moment['capture'],
                ];
            }
        }

        return collect($evidence)
            ->filter(fn (array $item): bool => $item['capture'] !== '')
            ->take(12)
            ->values()
            ->all();
    }

    /**
     * Crawl the currently published site as ephemeral evidence. The target
     * list is explicit when configured and otherwise comes from the selected
     * visitor journeys, so the analysis does not blindly crawl an unbounded
     * site by default.
     *
     * @param array<int, array<string, mixed>> $sessions
     * @param array<string, mixed> $configuration
     * @param array<int, array<string, mixed>> $evidence
     * @param array<string, mixed> $sourceStatus
     * @return array<string, mixed>
     */
    private function liveCrawlContext(Site $site, array $sessions, array $configuration, array &$evidence, array &$sourceStatus): array
    {
        $options = array_replace([
            'scope' => 'page',
            'urls' => [],
            'max_pages' => 8,
            'max_depth' => 1,
        ], (array) ($configuration['crawl_options'] ?? []));
        $fallbackTargets = collect($sessions)
            ->flatMap(function (array $session): array {
                return array_merge(
                    [$session['entry_url'] ?? null, $session['exit_url'] ?? null],
                    collect(data_get($session, 'journey.page_sequence', []))->pluck('path')->all(),
                );
            })
            ->filter(fn ($url): bool => is_string($url) && trim($url) !== '')
            ->map(fn (string $url): string => trim($url))
            ->unique()
            ->take(10)
            ->values()
            ->all();

        $crawl = $this->liveCrawler->crawl($site, $options, $fallbackTargets);
        foreach ($crawl['pages'] ?? [] as &$page) {
            $url = (string) ($page['url'] ?? '');
            $evidenceId = 'live_crawl:'.substr(sha1($url), 0, 12);
            $page['evidence_id'] = $evidenceId;
            foreach (['sections', 'links', 'ctas', 'forms'] as $elementGroup) {
                $page[$elementGroup] = collect($page[$elementGroup] ?? [])
                    ->map(function (mixed $element) use ($evidenceId): mixed {
                        if (! is_array($element)) return $element;
                        $element['page_evidence_id'] = $evidenceId;
                        return $element;
                    })
                    ->values()
                    ->all();
            }
            $this->registerEvidence($evidence, $evidenceId, 'live_crawl', 'Lecture live de la page publiée', [
                'url' => $url,
                'title' => $page['title'] ?? null,
                'status_code' => $page['status_code'] ?? null,
                'render_mode' => $page['render_mode'] ?? ($crawl['render_mode'] ?? 'server_html'),
                'javascript_rendered' => (bool) ($page['javascript_rendered'] ?? ($crawl['javascript_rendered'] ?? false)),
                'selectors' => collect($page['ctas'] ?? [])->pluck('selector')->filter()->values()->all(),
                'scope' => $crawl['scope'] ?? null,
                'read_only' => true,
                'knowledge_index_updated' => false,
            ]);
        }
        unset($page);

        $sourceStatus['live_crawl'] = [
            'status' => $crawl['status'] ?? 'unavailable',
            'scope' => $crawl['scope'] ?? ($options['scope'] ?? 'page'),
            'render_mode' => $crawl['render_mode'] ?? 'server_html',
            'javascript_rendered' => (bool) ($crawl['javascript_rendered'] ?? false),
            'requested_urls' => count($crawl['requested_urls'] ?? []),
            'fetched_pages' => count($crawl['pages'] ?? []),
            'inspected_urls' => collect($crawl['pages'] ?? [])->pluck('url')->filter()->values()->all(),
            'pages' => collect($crawl['pages'] ?? [])->map(fn (array $page): array => [
                'url' => $page['url'] ?? null,
                'status_code' => $page['status_code'] ?? null,
                'title' => $page['title'] ?? null,
                'render_mode' => $page['render_mode'] ?? ($crawl['render_mode'] ?? 'server_html'),
                'headings' => count($page['headings'] ?? []),
                'sections' => count($page['sections'] ?? []),
                'links' => count($page['links'] ?? []),
                'ctas' => count($page['ctas'] ?? []),
                'forms' => count($page['forms'] ?? []),
            ])->values()->all(),
            'max_pages' => (int) data_get($crawl, 'limits.max_pages', 8),
            'max_depth' => (int) data_get($crawl, 'limits.max_depth', 1),
            'read_only' => true,
            'knowledge_index_updated' => false,
            'errors' => array_values($crawl['errors'] ?? []),
            'reason' => ($crawl['status'] ?? null) === 'unavailable'
                ? 'Aucune page publiée n’a pu être lue par le crawl live.'
                : null,
        ];

        return [
            'status' => $crawl['status'] ?? 'unavailable',
            'scope' => $crawl['scope'] ?? ($options['scope'] ?? 'page'),
            'render_mode' => $crawl['render_mode'] ?? 'server_html',
            'javascript_rendered' => (bool) ($crawl['javascript_rendered'] ?? false),
            'requested_urls' => $crawl['requested_urls'] ?? [],
            'pages' => array_values($crawl['pages'] ?? []),
            'errors' => array_values($crawl['errors'] ?? []),
            'limits' => $crawl['limits'] ?? [],
        ];
    }

    /** @param array<string, mixed> $sourceStatus @return array<string, mixed> */
    private function liveCrawlNotRequested(array &$sourceStatus): array
    {
        $sourceStatus['live_crawl'] = [
            'status' => 'not_requested',
            'render_mode' => null,
            'javascript_rendered' => false,
            'inspected_urls' => [],
            'pages' => [],
            'read_only' => true,
            'knowledge_index_updated' => false,
            'reason' => 'Le crawl live est désactivé dans la configuration de cet agent.',
        ];
        return [
            'status' => 'not_requested',
            'scope' => null,
            'requested_urls' => [],
            'pages' => [],
            'errors' => [],
            'limits' => ['read_only' => true, 'knowledge_index_updated' => false],
        ];
    }

    private function knowledgeContext(Site $site, array $sessions, array &$evidence, array &$sourceStatus, array $configuration = []): array
    {
        $knowledgeOptions = (array) ($configuration['knowledge_options'] ?? []);
        $useForDiagnosis = ($knowledgeOptions['use_knowledge_for_diagnosis'] ?? true) === true;
        $useForRecommendations = ($knowledgeOptions['use_knowledge_for_recommendations'] ?? true) === true;

        $diagnosticQueries = $useForDiagnosis
            ? collect($sessions)->flatMap(function (array $session): array {
                $summaryQueries = collect(data_get($session, 'summary.unresolved_questions', []))->filter();
                $conversationQueries = collect($session['conversations'] ?? [])
                    ->flatMap(fn (array $conversation): array => collect($conversation['signals'] ?? [])
                        ->filter(fn (array $signal): bool => in_array($signal['type'] ?? null, ['knowledge_gap', 'clarification', 'objection_or_friction'], true))
                        ->pluck('text')->all());
                $frictionPoints = collect(data_get($session, 'summary.friction_points', []))->filter();
                return $summaryQueries->merge($conversationQueries)->merge($frictionPoints)->all();
            })
                ->filter(fn ($item): bool => is_string($item) && trim($item) !== '')
                ->map(fn (string $item): string => Str::limit($item, 300, ''))
                ->unique()
                ->take(5)
                ->values()
            : collect();
        $contentQueries = collect();
        if ($useForRecommendations) {
            $objective = (string) ($configuration['primary_objective'] ?? 'lead_generation');
            $areas = implode(', ', array_slice((array) ($configuration['priority_areas'] ?? []), 0, 4));
            $contentQueries->push(Str::limit(
                "Dans la base Knowledge de ce tenant, retrouve les informations validées sur les offres, services, bénéfices, preuves, objections, FAQ, pages et appels à l’action utiles pour l’objectif {$objective} ({$areas}). Retourne des éléments utilisables pour rédiger une proposition précise, sans inventer de contenu.",
                300,
                '',
            ));
            $pages = collect($sessions)
                ->flatMap(function (array $session): array {
                    $journeyPages = collect(data_get($session, 'journey.page_sequence', []))
                        ->pluck('path')
                        ->all();

                    return array_merge([
                        $session['entry_url'] ?? null,
                        $session['exit_url'] ?? null,
                    ], $journeyPages);
                })
                ->filter(fn ($page): bool => is_string($page) && trim($page) !== '')
                ->map(fn (string $page): string => Str::limit($page, 500, ''))
                ->unique()
                ->take(4)
                ->values();

            $pages->each(fn (string $page) => $contentQueries->push(Str::limit(
                "Contenu validé dans Knowledge pour la page {$page} : intitulé de page, proposition de valeur, sections, bénéfices, preuves, objections, FAQ, libellé et destination du CTA à recommander.",
                350,
                '',
            )));
            $contentQueries = $contentQueries->unique()->take(5)->values();
        }
        $queries = $diagnosticQueries->merge($contentQueries)->unique()->values();
        $results = [];
        foreach ($queries as $query) {
            $toolResult = $this->rag->search($site, $query, 5, new ActorContext('system', (string) $site->id, true));
            $evidenceId = 'knowledge:'.substr(sha1($query), 0, 12);
            $this->registerEvidence($evidence, $evidenceId, 'knowledge_base', 'Recherche ciblée dans Knowledge', [
                'query' => $query,
                'success' => $toolResult->success,
            ]);
            $results[] = [
                'evidence_id' => $evidenceId,
                'query' => $query,
                'purpose' => $contentQueries->contains($query) ? 'content_proposal' : 'diagnosis',
                'status' => $toolResult->success ? 'ready' : ($toolResult->errorCode ?? 'unavailable'),
                'results' => $toolResult->success ? ($toolResult->data['results'] ?? []) : [],
            ];
        }
        $sourceStatus['knowledge_base'] = [
            'status' => $queries->isEmpty() ? 'not_requested' : (collect($results)->contains('status', 'ready') ? 'ready' : 'unavailable'),
            'queries' => $queries->count(),
            'content_queries' => $contentQueries->count(),
            'reason' => $queries->isEmpty() ? 'Aucune question, friction ou opportunité de contenu exploitable n’a généré de requête ciblée.' : null,
        ];
        return $results;
    }

    /** @return array<int, mixed> */
    private function knowledgeNotRequested(array &$sourceStatus): array
    {
        $sourceStatus['knowledge_base'] = [
            'status' => 'not_requested',
            'queries' => 0,
            'reason' => 'Knowledge est désactivé dans la configuration de cet agent.',
        ];
        return [];
    }

    /** @param array<string, mixed> $journey @param array<string, mixed> $options */
    private function applyJourneyOptions(array $journey, array $options): array
    {
        if (($options['detect_conversion_paths'] ?? true) === false) $journey['conversion_paths'] = [];
        if (($options['detect_abandonment_paths'] ?? true) === false) $journey['drop_off'] = [];
        if (($options['compare_acquisition_sources'] ?? true) === false) $journey['acquisition'] = [];
        return $journey;
    }

    private function externalInvestigationPlan(array $sessions, array $configuration = [], array $plan = []): array
    {
        $acquisition = collect($sessions)->pluck('acquisition')->filter()->values();
        $sources = $acquisition->flatMap(fn (array $item): array => [
            Str::lower((string) ($item['source_type'] ?? '')),
            Str::lower((string) ($item['source'] ?? '')),
            Str::lower((string) ($item['medium'] ?? '')),
        ])->filter()->values();
        $organic = $sources->contains(fn (string $source): bool => Str::contains($source, ['organic', 'search', 'google', 'bing', 'seo']));
        // Preserve the direct-call contract used by older integrations/tests;
        // the runtime path always supplies the explicit planner output.
        $legacyPlanCall = $plan === [];
        $gaEnabled = $legacyPlanCall ? true : (bool) data_get($plan, 'sources.google_analytics', false);
        $gscEnabled = $legacyPlanCall ? true : (bool) data_get($plan, 'sources.search_console', false);
        return [
            'google_analytics' => $gaEnabled,
            'google_search_console' => $legacyPlanCall ? $organic : $gscEnabled,
            'compare_previous_period' => (bool) ($configuration['compare_previous_period'] ?? false),
            'reasons' => [
                'google_analytics' => $gaEnabled
                    ? 'Source activée dans la configuration : mesurer le trafic, les pages, les sources et conversions.'
                    : 'Google Analytics est désactivé dans la configuration de cet agent.',
                'google_search_console' => ! $gscEnabled
                    ? 'Search Console est désactivé dans la configuration de cet agent.'
                    : ($organic
                        ? 'Source activée et cohérente avec un signal d’acquisition organique ou recherche.'
                        : 'Source activée par la configuration ; aucun signal organique explicite n’a été trouvé dans les parcours sélectionnés.'),
            ],
        ];
    }

    private function externalContext(Site $site, Conversation $systemConversation, Carbon $from, Carbon $to, array $plan, array &$evidence, array &$sourceStatus): array
    {
        $dateTo = $to->toDateString();
        $gscPeriod = $this->searchConsolePeriod($from, $to);
        $calls = [
            'google_analytics' => [
                ['tool' => 'get_traffic_overview', 'params' => ['date_from' => $from->toDateString(), 'date_to' => $dateTo, 'compare_previous_period' => (bool) ($plan['compare_previous_period'] ?? false)]],
                ['tool' => 'get_top_pages', 'params' => ['date_from' => $from->toDateString(), 'date_to' => $dateTo, 'limit' => 10]],
                ['tool' => 'get_traffic_sources', 'params' => ['date_from' => $from->toDateString(), 'date_to' => $dateTo]],
                ['tool' => 'get_conversions', 'params' => ['date_from' => $from->toDateString(), 'date_to' => $dateTo]],
            ],
            'google_search_console' => [
                ['tool' => 'get_search_analytics', 'params' => ['date_from' => $gscPeriod['from'], 'date_to' => $gscPeriod['to'], 'dimension' => 'query', 'limit' => 20]],
                ['tool' => 'get_search_analytics', 'params' => ['date_from' => $gscPeriod['from'], 'date_to' => $gscPeriod['to'], 'dimension' => 'page', 'limit' => 20]],
                ['tool' => 'list_sitemaps', 'params' => []],
            ],
        ];
        if (! ($plan['google_analytics'] ?? false)) {
            unset($calls['google_analytics']);
            $sourceStatus['google_analytics'] = [
                'status' => 'not_requested',
                'reason' => $plan['reasons']['google_analytics'] ?? 'Investigation désactivée dans la configuration.',
                'successful_calls' => 0,
                'requested_calls' => 0,
            ];
        }
        if (! ($plan['google_search_console'] ?? false)) {
            unset($calls['google_search_console']);
            $sourceStatus['google_search_console'] = [
                'status' => 'not_requested',
                'reason' => $plan['reasons']['google_search_console'] ?? 'Investigation non nécessaire selon les signaux disponibles.',
                'successful_calls' => 0,
                'requested_calls' => 0,
            ];
        }
        $external = [];
        foreach ($calls as $connector => $connectorCalls) {
            $external[$connector] = [];
            $successes = 0;
            foreach ($connectorCalls as $call) {
                $qualified = $connector.'__'.$call['tool'];
                try {
                    $result = $this->mcp->executeToolDirectly($site, $systemConversation, $qualified, $call['params'], systemActor: true);
                } catch (PermissionDeniedException $exception) {
                    $result = ToolResult::fail('permission_denied', $exception->getMessage());
                } catch (AuthExpiredException $exception) {
                    $result = ToolResult::fail('auth_expired', $exception->getMessage());
                } catch (ConnectorUnavailableException $exception) {
                    $result = ToolResult::fail('connector_unavailable', $exception->getMessage());
                } catch (Throwable $exception) {
                    $result = ToolResult::fail('collection_error', Str::limit($exception->getMessage(), 500, ''));
                }
                $evidenceId = 'mcp:'.$qualified;
                $this->registerEvidence($evidence, $evidenceId, 'mcp_read', $qualified, ['connector' => $connector, 'tool' => $call['tool']]);
                $external[$connector][] = [
                    'evidence_id' => $evidenceId,
                    'tool' => $call['tool'],
                    'status' => $result->success ? 'ready' : ($result->errorCode ?? 'unavailable'),
                    'data' => $result->success ? $result->data : [],
                    'message' => $result->success ? $result->humanSummary : $result->errorMessage,
                ];
                if ($result->success) $successes++;
            }
            $sourceStatus[$connector] = [
                'status' => $successes === count($connectorCalls) ? 'ready' : ($successes > 0 ? 'partial' : 'unavailable'),
                'successful_calls' => $successes,
                'requested_calls' => count($connectorCalls),
            ];
        }
        $external['google_analytics'] ??= [];
        $external['google_search_console'] ??= [];
        return $external;
    }

    /**
     * Search Console data can lag by a few days. Keep that protection for
     * normal periods, but never send an inverted range for a short custom
     * period such as 20/09 -> 22/09.
     *
     * @return array{from: string, to: string}
     */
    private function searchConsolePeriod(Carbon $from, Carbon $to): array
    {
        $gscFrom = $from->copy()->startOfDay();
        $gscTo = $to->copy()->subDays(3)->startOfDay();
        if ($gscTo->lt($gscFrom)) $gscTo = $gscFrom->copy();

        return [
            'from' => $gscFrom->toDateString(),
            'to' => $gscTo->toDateString(),
        ];
    }

    private function registerEvidence(array &$evidence, string $id, string $type, string $label, array $metadata = []): void
    {
        if (collect($evidence)->contains('id', $id)) return;
        $evidence[] = ['id' => $id, 'type' => $type, 'label' => $label, 'metadata' => $metadata];
    }

    private function period(array $filters): array
    {
        $to = ! empty($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : now()->endOfDay();
        $from = ! empty($filters['from']) ? Carbon::parse($filters['from'])->startOfDay() : $to->copy()->subDays(28)->startOfDay();
        if ($from->gt($to)) [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        $maxDays = max(1, (int) config('analytics.max_period_days', 366));
        if ($from->diffInDays($to) > $maxDays) $from = $to->copy()->subDays($maxDays)->startOfDay();
        return [$from, $to];
    }

    private function safeText(mixed $value, int $limit): ?string
    {
        if ($value === null || ! is_scalar($value)) return null;
        $text = trim((string) $value);
        if ($text === '') return null;
        $text = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu', '[email]', $text) ?? $text;
        $text = preg_replace('/(?:\+?\d[\d .()\-]{7,}\d)/u', '[phone]', $text) ?? $text;
        return Str::limit(preg_replace('/\s+/u', ' ', $text) ?? $text, $limit, '…');
    }

    private function progress(?callable $progress, int $percent, string $phase, string $message): void
    {
        if ($progress !== null) $progress(['progress' => $percent, 'phase' => $phase, 'message' => $message]);
    }

    /** @return array<string, mixed> */
    private function widgetContext(?WidgetSetting $settings): array
    {
        if (! $settings) {
            return [
                'status' => 'not_installed',
                'installed' => false,
                'configured' => false,
                'enabled' => false,
                'ai_enabled' => false,
                'visitor_intelligence_ai_enabled' => false,
                'ai_engagement_enabled' => false,
                'ai_engagement_widget_behavior' => null,
                'bot_language' => null,
                'bot_name' => null,
                'auto_open_enabled' => false,
                'require_authentication' => false,
            ];
        }

        $enabled = (bool) $settings->widget_enabled;

        return [
            'status' => $enabled ? 'enabled' : 'disabled',
            'installed' => true,
            'configured' => true,
            'enabled' => $enabled,
            'ai_enabled' => (bool) $settings->ai_enabled,
            'visitor_intelligence_ai_enabled' => (bool) $settings->visitor_intelligence_ai_enabled,
            'ai_engagement_enabled' => (bool) $settings->ai_engagement_enabled,
            'ai_engagement_widget_behavior' => $settings->ai_engagement_widget_behavior,
            'bot_language' => $settings->bot_language,
            'bot_name' => $settings->bot_name,
            'auto_open_enabled' => (bool) $settings->auto_open_enabled,
            'auto_open_delay' => $settings->auto_open_delay,
            'require_authentication' => (bool) $settings->require_authentication,
            'theme' => [
                'primary' => $settings->theme_primary,
                'secondary' => $settings->theme_secondary,
                'background' => $settings->theme_background,
                'color' => $settings->theme_color,
            ],
        ];
    }
}
