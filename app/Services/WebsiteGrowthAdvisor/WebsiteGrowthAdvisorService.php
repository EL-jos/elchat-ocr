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
use App\Services\VisitorIntelligence\VisitorIntelligenceMomentDetector;
use App\Services\VisitorIntelligence\VisitorIntelligenceQueryService;
use App\Services\VisitorIntelligence\VisitorIntelligenceReplayContextService;
use App\Services\hops\LLMService;
use App\Services\mcp\MCPActionGateService;
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
        private readonly VisitorIntelligenceMomentDetector $momentDetector,
        private readonly VisitorIntelligenceReplayContextService $replayContext,
        private readonly MCPActionGateService $mcp,
        private readonly WebsiteGrowthAdvisorConversationCleanupService $conversationCleanup,
        private readonly RAGToolAdapter $rag,
        private readonly LLMService $llm,
    ) {
    }

    /** @return array<string, mixed> */
    public function collectSnapshot(Site $site, array $filters = [], bool $includeExternal = true): array
    {
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

        $overview = $this->visitorIntelligence->overview($site, $periodFilters);
        $journey = $this->visitorIntelligence->journey($site, $periodFilters);
        $this->registerEvidence($evidence, 'visitor_intelligence:overview', 'visitor_intelligence', 'Vue d’ensemble Visitor Intelligence', [
            'period' => [$from->toISOString(), $to->toISOString()],
        ]);

        $sessions = VisitorSession::query()
            ->where('site_id', $site->id)
            ->whereBetween('started_at', [$from, $to])
            ->with('summary')
            ->orderByDesc('converted')
            ->orderByDesc('has_widget_interaction')
            ->orderByDesc('page_count')
            ->limit(self::MAX_CANDIDATE_SESSIONS)
            ->get();

        $representative = $this->selectRepresentativeSessions($sessions);
        $sessionKeys = $representative->pluck('session_key')->filter()->values();
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

        $conversationIds = $eventRows->pluck('conversation_id')->filter()->unique()->values();
        $conversations = $conversationIds->isEmpty()
            ? collect()
            : Conversation::query()
                ->where('site_id', $site->id)
                ->whereIn('id', $conversationIds)
                ->get(['id', 'summary', 'status', 'created_at', 'updated_at'])
                ->keyBy(fn (Conversation $conversation): string => (string) $conversation->id);

        $messagesByConversation = $this->messagesByConversation($conversationIds);
        $visualSessions = $representative->take(self::MAX_VISUAL_SESSIONS);
        $visualMomentsCount = 0;
        $sessionContexts = [];

        foreach ($representative as $session) {
            $sessionEvents = $eventRows->where('session_id', $session->session_key)->values();
            $moments = $this->momentDetector->detect($sessionEvents, max(1, (int) config('visitor-intelligence.ai.max_moments', 12)));
            $visual = ['available' => false, 'moments' => []];

            if ($visualSessions->contains('id', $session->id)) {
                $visualCandidates = collect($moments)
                    ->filter(fn (array $moment): bool => (bool) ($moment['needs_visual_context'] ?? false))
                    ->take(self::MAX_VISUAL_MOMENTS_PER_SESSION)
                    ->values()
                    ->all();
                if ($visualCandidates !== []) {
                    $visual = $this->replayContext->build($session, $visualCandidates);
                    $visualMomentsCount += count($visual['moments'] ?? []);
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
                'acquisition' => [
                    'source_type' => $session->acquisition_source_type,
                    'source' => $session->acquisition_source ?: $session->source,
                    'medium' => $session->acquisition_medium,
                    'campaign' => $session->acquisition_campaign,
                    'platform' => $session->acquisition_platform,
                    'confidence' => $session->acquisition_confidence,
                ],
                'page_count' => (int) $session->page_count,
                'duration_seconds' => $session->duration_seconds,
                'has_widget_interaction' => (bool) $session->has_widget_interaction,
                'intent_level' => $session->intent_level,
                'converted' => (bool) $session->converted,
                'summary' => $this->summaryContext($session->summary),
                'events' => $this->eventContext($sessionEvents, $evidence),
                'conversations' => $this->conversationContext($sessionEvents, $conversations, $messagesByConversation),
                'moments' => array_values($moments),
                'visual_context' => $this->visualContext($visual),
            ];
        }

        $knowledge = $this->knowledgeContext($site, $sessionContexts, $evidence, $sourceStatus);
        $systemConversation = $includeExternal ? Conversation::create([
            'site_id' => $site->id,
            'user_id' => null,
            'visitor_id' => null,
            'metadata' => $this->conversationCleanup->temporaryMetadata(),
        ]) : null;
        try {
            $external = $includeExternal
                ? $this->externalContext($site, $systemConversation, $from, $to, $evidence, $sourceStatus)
                : ['google_analytics' => [], 'google_search_console' => []];
        } finally {
            $this->conversationCleanup->cleanup($systemConversation);
        }

        $sourceStatus['visitor_intelligence'] = [
            'status' => $sessions->isNotEmpty() ? 'ready' : 'empty',
            'candidate_sessions' => $sessions->count(),
            'representative_sessions' => count($sessionContexts),
        ];

        return [
            'period' => ['from' => $from->toISOString(), 'to' => $to->toISOString()],
            'overview' => $overview,
            'journey' => $journey,
            'widget' => $widget,
            'representative_sessions' => $sessionContexts,
            'knowledge_base' => $knowledge,
            'external' => $external,
            'source_status' => $sourceStatus,
            'evidence' => array_values($evidence),
            'collection_limits' => [
                'candidate_sessions_limit' => self::MAX_CANDIDATE_SESSIONS,
                'representative_sessions_limit' => self::MAX_REPRESENTATIVE_SESSIONS,
                'visual_sessions_limit' => self::MAX_VISUAL_SESSIONS,
                'raw_replay_sent_to_llm' => false,
                'visual_moments_rendered' => $visualMomentsCount,
            ],
        ];
    }

    public function analyze(WebsiteGrowthAdvisorAnalysis $analysis, ?callable $progress = null): WebsiteGrowthAdvisorAnalysis
    {
        $analysis->forceFill([
            'status' => 'running',
            'started_at' => now(),
            'error_message' => null,
        ])->save();
        $this->progress($progress, 10, 'collection', 'Collecte des signaux observés.');

        try {
            $site = $analysis->site()->firstOrFail();
            $snapshot = $this->collectSnapshot($site, [
                'from' => $analysis->period_from,
                'to' => $analysis->period_to,
            ], (bool) $analysis->include_external);
            $analysis->forceFill([
                'data_snapshot' => $snapshot,
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
        $evidenceIds = collect($snapshot['evidence'] ?? [])->pluck('id')->filter()->values()->all();
        $fallback = $this->deterministicResult($snapshot);

        if (! config('llm.provider.api_key') && ! config('mcp.llm.api_key')) {
            return [
                ...$fallback,
                'meta' => ['ai_status' => 'unavailable', 'warning' => 'llm_api_key_missing'],
            ];
        }

        $messages = [
            [
                'role' => 'system',
            'content' => 'Tu es Website Growth Advisor d’ELChat. Analyse uniquement les données fournies. Utilise explicitement la section widget pour tenir compte de l’installation, de l’activation et de la configuration du widget du tenant; ne suppose jamais qu’un widget est installé si widget.installed vaut false. Distingue strictement observed_facts (faits mesurés), correlations (relations observées entre sources), inferences (hypothèses prudentes) et recommendations (actions proposées, jamais exécutées). Une causalité ne peut pas être affirmée sans preuve. Utilise uniquement les priorités high, medium ou low; high signifie un signal business ou une friction documentée et récurrente, medium un signal utile mais moins établi, low une piste exploratoire. Chaque élément important doit citer un ou plusieurs evidence_id réellement présents. Réponds exclusivement avec un objet JSON valide.',
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'task' => 'Construire un diagnostic de croissance exploitable et vérifiable.',
                    'output_schema' => [
                        'executive_summary' => 'string',
                        'observed_facts' => [['id' => 'string', 'text' => 'string', 'evidence' => ['evidence_id']]],
                        'correlations' => [['id' => 'string', 'text' => 'string', 'evidence' => ['evidence_id']]],
                        'inferences' => [['id' => 'string', 'text' => 'string', 'confidence' => 'number 0-100', 'evidence' => ['evidence_id']]],
                        'priorities' => [['id' => 'string', 'title' => 'string', 'priority' => 'high|medium|low', 'why' => 'string', 'evidence' => ['evidence_id']]],
                        'recommendations' => [['id' => 'string', 'title' => 'string', 'priority' => 'high|medium|low', 'action' => 'string', 'basis' => 'string', 'evidence' => ['evidence_id']]],
                        'tests' => [['id' => 'string', 'hypothesis' => 'string', 'change' => 'string', 'metric' => 'string', 'success_condition' => 'string', 'window' => 'string', 'evidence' => ['evidence_id']]],
                        'data_gaps' => ['string'],
                    ],
                    'valid_evidence_ids' => $evidenceIds,
                    'data' => $snapshot,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ],
        ];

        try {
            $raw = $this->llm->chatJson($messages, [
                'task' => 'website_growth_advisor',
                'temperature' => 0.1,
                'max_tokens' => 2600,
                'max_tokens_cap' => 4200,
                'detect_truncation' => true,
                'response_format' => ['type' => 'json_object'],
                'request_timeout' => (int) config('llm.tasks.website_growth_advisor.request_timeout', 90),
            ]);

            $normalized = $this->normalizeResult($raw, $evidenceIds);
            if ($normalized !== []) {
                return [
                    ...$normalized,
                    'meta' => [
                        'ai_status' => 'ready',
                        'model' => $this->llm->lastUsedModel(),
                        'raw_replay_sent_to_llm' => false,
                    ],
                ];
            }
        } catch (Throwable) {
            // A deterministic result remains useful and explicitly indicates
            // that the optional synthesis step was unavailable.
        }

        return [
            ...$fallback,
            'meta' => ['ai_status' => 'failed', 'warning' => 'llm_synthesis_unavailable', 'raw_replay_sent_to_llm' => false],
        ];
    }

    /** @return array<string, mixed> */
    private function deterministicResult(array $snapshot): array
    {
        $kpis = collect(data_get($snapshot, 'overview.kpis', []))->keyBy('key');
        $facts = [];
        foreach (['sessions', 'conversations', 'leads', 'conversions', 'abandoned_sessions'] as $key) {
            if ($kpis->has($key)) {
                $facts[] = [
                    'id' => 'fact:kpi:'.$key,
                    'text' => (string) ($kpis[$key]['label'] ?? $key).' : '.(string) ($kpis[$key]['value'] ?? 0).'.',
                    'evidence' => ['visitor_intelligence:overview'],
                ];
            }
        }

        $recommendations = [];
        $dropOff = data_get($snapshot, 'journey.drop_off', []);
        if ($dropOff !== []) {
            $recommendations[] = [
                'id' => 'recommendation:drop_off',
                'title' => 'Examiner les pages de sortie récurrentes',
                'priority' => 'medium',
                'action' => 'Comparer le contenu, les appels à l’action et les conversations associés aux pages de sortie avant de modifier le site.',
                'basis' => 'Les sorties sont observées dans les parcours non convertis; elles ne prouvent pas à elles seules une cause.',
                'evidence' => ['visitor_intelligence:overview'],
            ];
        }

        return [
            'executive_summary' => 'Synthèse déterministe disponible; la synthèse LLM n’a pas produit de résultat exploitable.',
            'observed_facts' => $facts,
            'correlations' => [],
            'inferences' => [],
            'priorities' => [],
            'recommendations' => $recommendations,
            'tests' => [],
            'data_gaps' => collect($snapshot['source_status'] ?? [])->filter(fn ($s) => ($s['status'] ?? null) !== 'ready')->keys()->map(fn ($key) => "Source {$key} indisponible ou incomplète.")->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function normalizeResult(array $raw, array $validEvidenceIds): array
    {
        $normalizeEvidence = function (mixed $items) use ($validEvidenceIds): array {
            $items = is_array($items) ? $items : [];
            return collect($items)->map(function ($item) use ($validEvidenceIds): ?string {
                $id = is_array($item) ? ($item['evidence_id'] ?? $item['id'] ?? null) : $item;
                return is_string($id) && in_array($id, $validEvidenceIds, true) ? $id : null;
            })->filter()->unique()->take(10)->values()->all();
        };
        $text = fn (mixed $value, int $limit = 1200): ?string => $this->safeText($value, $limit);
        $claims = function (mixed $value, bool $withConfidence = false) use ($normalizeEvidence, $text): array {
            return collect(is_array($value) ? $value : [])->map(function ($item) use ($normalizeEvidence, $text, $withConfidence): ?array {
                if (! is_array($item)) return null;
                $claim = $text($item['text'] ?? $item['claim'] ?? null);
                if (! $claim) return null;
                $result = ['id' => $text($item['id'] ?? null, 100) ?: 'claim:'.substr(sha1($claim), 0, 12), 'text' => $claim, 'evidence' => $normalizeEvidence($item['evidence'] ?? [])];
                if ($withConfidence) $result['confidence'] = is_numeric($item['confidence'] ?? null) ? max(0, min(100, (int) round($item['confidence']))) : null;
                return $result;
            })->filter()->values()->take(30)->all();
        };
        $priorities = $this->normalizeStructuredItems($raw['priorities'] ?? [], $normalizeEvidence, $text, 'priority', ['high', 'medium', 'low']);
        $recommendations = $this->normalizeStructuredItems($raw['recommendations'] ?? [], $normalizeEvidence, $text, 'priority', ['high', 'medium', 'low'], ['action', 'basis']);
        $tests = $this->normalizeStructuredItems($raw['tests'] ?? [], $normalizeEvidence, $text, null, [], ['hypothesis', 'change', 'metric', 'success_condition', 'window']);

        return [
            'executive_summary' => $text($raw['executive_summary'] ?? null, 2400) ?: null,
            'observed_facts' => $claims($raw['observed_facts'] ?? []),
            'correlations' => $claims($raw['correlations'] ?? []),
            'inferences' => $claims($raw['inferences'] ?? [], true),
            'priorities' => $priorities,
            'recommendations' => $recommendations,
            'tests' => $tests,
            'data_gaps' => collect(is_array($raw['data_gaps'] ?? null) ? $raw['data_gaps'] : [])->map(fn ($item) => $text($item, 500))->filter()->unique()->take(20)->values()->all(),
        ];
    }

    private function normalizeStructuredItems(array $items, callable $normalizeEvidence, callable $text, ?string $enumKey, array $enumValues, array $extraKeys = []): array
    {
        return collect($items)->map(function ($item) use ($normalizeEvidence, $text, $enumKey, $enumValues, $extraKeys): ?array {
            if (! is_array($item)) return null;
            $title = $text($item['title'] ?? $item['text'] ?? null, 500);
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

    private function selectRepresentativeSessions(Collection $sessions): Collection
    {
        return $sessions->sortByDesc(function (VisitorSession $session): int {
            $summary = $session->summary;
            $score = 0;
            if ($session->converted) $score += 100;
            if ($session->has_widget_interaction) $score += 30;
            if ($session->intent_level === 'high') $score += 60;
            if ($summary && count((array) $summary->friction_points) > 0) $score += 45;
            if ($session->ended_at && ! $session->converted) $score += 20;
            return $score + min(20, (int) $session->page_count);
        })->take(self::MAX_REPRESENTATIVE_SESSIONS)->values();
    }

    private function messagesByConversation(Collection $conversationIds): Collection
    {
        $messages = collect();
        foreach ($conversationIds as $conversationId) {
            $messages[$conversationId] = Message::query()
                ->without('attachment')
                ->where('conversation_id', $conversationId)
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

    private function conversationContext(Collection $events, Collection $conversations, Collection $messagesByConversation): array
    {
        return $events->pluck('conversation_id')->filter()->unique()->map(function ($id) use ($conversations, $messagesByConversation): ?array {
            $conversation = $conversations->get((string) $id);
            if (! $conversation) return null;
            return [
                'conversation_id' => (string) $conversation->id,
                'status' => $conversation->status,
                'summary' => $this->safeText($conversation->summary, 1000),
                'messages' => collect($messagesByConversation->get($id, []))->map(fn (Message $message) => [
                    'message_id' => (string) $message->id,
                    'role' => $message->role,
                    'content' => $this->safeText($message->content, 1200),
                    'created_at' => $message->created_at?->toISOString(),
                ])->values()->all(),
            ];
        })->filter()->values()->all();
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

    private function visualContext(array $visual): array
    {
        return [
            'available' => (bool) ($visual['available'] ?? false),
            'error' => $visual['error'] ?? null,
            'moments' => collect($visual['moments'] ?? [])->map(fn (array $moment): array => [
                'moment_id' => $moment['id'] ?? null,
                'event_ids' => array_values($moment['event_ids'] ?? []),
                'reason' => $moment['reason'] ?? null,
                'replay_timestamp' => $moment['replay_timestamp'] ?? null,
                'relative_timestamp' => $moment['relative_timestamp'] ?? null,
                'page' => $moment['page'] ?? null,
                'scroll' => $moment['scroll'] ?? null,
                'visible_text' => $this->safeText($moment['visible_text'] ?? null, 1200),
                'visible_elements' => array_slice((array) ($moment['visible_elements'] ?? []), 0, 20),
                'capture_available' => ! empty($moment['capture']),
            ])->values()->all(),
        ];
    }

    private function knowledgeContext(Site $site, array $sessions, array &$evidence, array &$sourceStatus): array
    {
        $queries = collect($sessions)
            ->pluck('summary.unresolved_questions')
            ->flatten()
            ->filter(fn ($item): bool => is_string($item) && trim($item) !== '')
            ->map(fn (string $item): string => Str::limit($item, 300, ''))
            ->unique()
            ->take(3);
        $results = [];
        foreach ($queries as $query) {
            $toolResult = $this->rag->search($site, $query, 5, new ActorContext('system', (string) $site->id, true));
            if (! $toolResult->success) continue;
            $evidenceId = 'knowledge:'.substr(sha1($query), 0, 12);
            $this->registerEvidence($evidence, $evidenceId, 'knowledge_base', 'Résultats RAG pour une friction observée', ['query' => $query]);
            $results[] = ['evidence_id' => $evidenceId, 'query' => $query, 'results' => $toolResult->data['results'] ?? []];
        }
        $sourceStatus['knowledge_base'] = ['status' => $queries->isEmpty() ? 'not_requested' : ($results === [] ? 'unavailable' : 'ready'), 'queries' => $queries->count()];
        return $results;
    }

    private function externalContext(Site $site, Conversation $systemConversation, Carbon $from, Carbon $to, array &$evidence, array &$sourceStatus): array
    {
        $dateTo = $to->toDateString();
        $gscDateTo = $to->copy()->subDays(3)->toDateString();
        $calls = [
            'google_analytics' => [
                ['tool' => 'get_traffic_overview', 'params' => ['date_from' => $from->toDateString(), 'date_to' => $dateTo, 'compare_previous_period' => true]],
                ['tool' => 'get_top_pages', 'params' => ['date_from' => $from->toDateString(), 'date_to' => $dateTo, 'limit' => 10]],
                ['tool' => 'get_traffic_sources', 'params' => ['date_from' => $from->toDateString(), 'date_to' => $dateTo]],
                ['tool' => 'get_conversions', 'params' => ['date_from' => $from->toDateString(), 'date_to' => $dateTo]],
            ],
            'google_search_console' => [
                ['tool' => 'get_search_analytics', 'params' => ['date_from' => $from->toDateString(), 'date_to' => $gscDateTo, 'dimension' => 'query', 'limit' => 20]],
                ['tool' => 'get_search_analytics', 'params' => ['date_from' => $from->toDateString(), 'date_to' => $gscDateTo, 'dimension' => 'page', 'limit' => 20]],
                ['tool' => 'list_sitemaps', 'params' => []],
            ],
        ];
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
        return $external;
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
