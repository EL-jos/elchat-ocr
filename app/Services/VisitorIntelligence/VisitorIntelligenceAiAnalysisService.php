<?php

namespace App\Services\VisitorIntelligence;

use App\Models\AnalyticsEvent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\VisitorSession;
use App\Models\VisitorSessionSummary;
use App\Models\WidgetSetting;
use App\Services\hops\LLMService;
use App\Services\vision\TargetedReplayVisualInspectionTool;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class VisitorIntelligenceAiAnalysisService
{
    public function __construct(
        private readonly LLMService $llm,
        private readonly VisitorIntelligenceSessionEvidenceService $sessionEvidence,
        private readonly TargetedReplayVisualInspectionTool $replayVision,
    ) {
    }

    public function analyze(VisitorSession $session, bool $force = false, ?callable $progress = null): VisitorSessionSummary
    {
        $summary = VisitorSessionSummary::query()->firstOrCreate(
            ['visitor_session_id' => $session->id],
            [
                'account_id' => $session->account_id,
                'site_id' => $session->site_id,
                'summary' => null,
                'analysis_version' => 'deterministic-1',
            ],
        );

        $siteAiEnabled = WidgetSetting::query()
            ->where('site_id', $session->site_id)
            ->value('visitor_intelligence_ai_enabled');

        // Keep the preference enforced even if this service is called outside
        // the usual queued job path.
        if (!$force && in_array($siteAiEnabled, [false, 0, '0'], true)) {
            return $summary->forceFill([
                'ai_status' => 'disabled',
                'ai_error' => null,
                'ai_generated_at' => null,
            ])->save() ? $summary->fresh() : $summary;
        }

        if (!config('visitor-intelligence.ai.enabled', true)) {
            return $summary->forceFill([
                'ai_status' => 'disabled',
                'ai_error' => null,
                'ai_generated_at' => null,
            ])->save() ? $summary->fresh() : $summary;
        }

        if (!config('llm.provider.api_key') && !config('mcp.llm.api_key')) {
            return $summary->forceFill([
                'ai_status' => 'unavailable',
                'ai_error' => 'llm_api_key_missing',
                'ai_generated_at' => null,
            ])->save() ? $summary->fresh() : $summary;
        }

        $this->reportProgress($progress, 15, 'events', 'Lecture des événements comportementaux.');

        $rows = AnalyticsEvent::query()
            ->where('site_id', $session->site_id)
            ->where('session_id', $session->session_key)
            ->orderBy('occurred_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'event_type', 'resource_type', 'resource_id', 'label', 'metadata', 'conversation_id', 'occurred_at']);

        if ($rows->isEmpty()) {
            return $this->markFailed($summary, 'visitor_intelligence_events_missing');
        }

        $this->reportProgress($progress, 32, 'moments', 'Détection des moments importants du parcours.');
        $investigation = $this->sessionEvidence->investigate($session, $rows);
        $selectedMoments = $investigation['moments'];
        $this->reportProgress($progress, 55, 'replay_context', 'Reconstruction ciblée du contexte visuel rrweb.');
        $visualContext = $investigation['visual_context'];
        $visualMoments = $this->visualMoments($visualContext['moments'] ?? []);
        $visualEvidence = $this->visualEvidenceForLlm($session, $visualContext['moments'] ?? []);
        $visualInspection = $this->replayVision->inspect($visualEvidence);
        $validEventIds = $rows->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $validMomentIds = collect($selectedMoments)->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $eventTimestamps = $rows->mapWithKeys(fn (AnalyticsEvent $row): array => [
            (string) $row->id => $row->occurred_at?->valueOf(),
        ])->all();
        $momentTimestamps = collect($selectedMoments)->mapWithKeys(fn (array $moment): array => [
            (string) $moment['id'] => $moment['replay_timestamp'] ?? null,
        ])->all();

        $context = [
            'session' => $this->sessionContext($session),
            'deterministic_summary' => $this->deterministicContext($summary),
            'timeline' => $this->timelineContext($rows),
            'important_moments' => array_values($selectedMoments),
            'visual_context' => $visualMoments,
            'visual_evidence_analysis' => [
                'tool' => $visualInspection['tool'],
                'status' => $visualInspection['status'],
                'model' => $visualInspection['model'],
                'observation_count' => count($visualInspection['observations']),
                'observations' => $visualInspection['observations'],
                'error' => $visualInspection['error'],
                'contract' => 'Ces observations proviennent uniquement des captures ciblées. Elles décrivent un état visible à un instant et ne prouvent ni lecture, ni clic, ni intention.',
            ],
            'conversations' => $this->conversationContext($rows),
            'evidence_contract' => [
                'event_ids' => $validEventIds,
                'moment_ids' => $validMomentIds,
                'rule' => 'Chaque fait ou inférence important doit référencer au moins un event_id ou moment_id réellement fourni.',
            ],
        ];

        $messages = [
            [
                'role' => 'system',
                'content' => 'Tu es l’analyste de Visitor Intelligence d’ELChat. Analyse un parcours web à partir d’événements structurés, des observations visuelles produites par le modèle de vision pour quelques moments rrweb ciblés et, si disponible, de la conversation ELChat. Tu dois distinguer strictement les faits observés des inférences. Ne dis jamais qu’un visiteur a lu un contenu uniquement parce qu’il était visible : dis élément visible, probablement consulté ou formulation équivalente. N’invente aucune preuve, aucun événement, aucun élément visuel et aucune causalité. Réponds uniquement avec un objet JSON valide dans le format demandé.',
            ],
            [
                'role' => 'user',
                // The reasoning model receives Qwen's visual observations as
                // text. It never receives the screenshots or raw rrweb data.
                'content' => $this->multimodalContent($context, []),
            ],
        ];

        try {
            $this->reportProgress($progress, 72, 'llm', 'Interprétation du parcours par le modèle IA.');
            $raw = $this->llm->chatJson($messages, [
                'task' => 'visitor_intelligence_analysis',
                // Visual inspection is a separate call; do not send image
                // blocks to the text reasoning fallback.
                'fallback_models' => [config('llm.tasks.visitor_intelligence_analysis.fallback_model', 'deepseek/deepseek-v3.2')],
                'temperature' => 0.1,
                'max_tokens' => 1800,
                'max_tokens_cap' => 3000,
                'detect_truncation' => true,
                'response_format' => ['type' => 'json_object'],
                'request_timeout' => (int) config('visitor-intelligence.ai.request_timeout', 45),
            ]);
            $this->reportProgress($progress, 92, 'validation', 'Validation des preuves et préparation du résultat.');
            $analysis = $this->normalizeAnalysis($raw, $validEventIds, $validMomentIds, $eventTimestamps, $momentTimestamps, $visualMoments);
            if ($analysis === []) {
                throw new \RuntimeException('visitor_intelligence_ai_empty_json');
            }
            $analysis['visual_evidence_analysis'] = [
                'tool' => $visualInspection['tool'],
                'status' => $visualInspection['status'],
                'model' => $visualInspection['model'],
                'observation_count' => count($visualInspection['observations']),
                'error' => $visualInspection['error'],
            ];

            return $summary->forceFill([
                'ai_status' => 'ready',
                'ai_model' => $this->llm->lastUsedModel(),
                'ai_analysis' => $analysis,
                'ai_generated_at' => now(),
                'ai_error' => null,
            ])->save() ? $summary->fresh() : $summary;
        } catch (Throwable $exception) {
            return $this->markFailed($summary, Str::limit($exception->getMessage(), 1000, ''));
        }
    }

    private function sessionContext(VisitorSession $session): array
    {
        return [
            'started_at' => $session->started_at?->toISOString(),
            'ended_at' => $session->ended_at?->toISOString(),
            'entry_url' => $session->entry_url,
            'exit_url' => $session->exit_url,
            'device' => $session->device,
            'page_count' => (int) $session->page_count,
            'duration_seconds' => $session->duration_seconds,
            'has_widget_interaction' => (bool) $session->has_widget_interaction,
            'converted' => (bool) $session->converted,
        ];
    }

    private function deterministicContext(VisitorSessionSummary $summary): array
    {
        return [
            'summary' => $summary->summary,
            'intent_level' => $summary->intent_level,
            'probable_goal' => $summary->probable_goal,
            'probable_outcome' => $summary->probable_outcome,
            'friction_points' => array_slice((array) $summary->friction_points, 0, 20),
            'purchase_signals' => array_slice((array) $summary->purchase_signals, 0, 20),
            'important_pages' => array_slice((array) $summary->important_pages, 0, 20),
        ];
    }

    private function timelineContext(Collection $rows): array
    {
        $max = max(20, (int) config('visitor-intelligence.ai.max_timeline_events', 180));
        $semantic = $rows->reject(fn (AnalyticsEvent $row): bool => $row->event_type === 'pointer_move');
        $pointerMoves = $rows->where('event_type', 'pointer_move')->count();

        $events = $semantic->take($max)->map(function (AnalyticsEvent $row): array {
            $metadata = is_array($row->metadata) ? $row->metadata : [];
            return [
                'event_id' => (string) $row->id,
                'event_type' => (string) $row->event_type,
                'occurred_at' => $row->occurred_at?->toISOString(),
                'path' => data_get($metadata, 'path'),
                'page_url' => data_get($metadata, 'page_url'),
                'label' => $this->safeText($row->label, 180),
                'resource_type' => $row->resource_type,
                'resource_id' => $row->resource_id,
                'metadata' => $this->safeMetadata($metadata),
                'conversation_id' => $row->conversation_id,
            ];
        })->values()->all();

        if ($pointerMoves > 0) {
            $events[] = [
                'event_id' => null,
                'event_type' => 'pointer_move_aggregate',
                'count' => $pointerMoves,
                'note' => 'Les mouvements de pointeur sont agrégés et ne sont pas interprétés individuellement.',
            ];
        }

        return $events;
    }

    private function conversationContext(Collection $rows): array
    {
        $ids = $rows->pluck('conversation_id')->filter()->unique()->values();
        if ($ids->isEmpty()) return [];

        $conversations = Conversation::query()
            ->whereIn('id', $ids)
            ->get(['id', 'summary', 'status', 'created_at', 'updated_at'])
            ->keyBy(fn (Conversation $conversation): string => (string) $conversation->id);

        return $ids->map(function ($id) use ($conversations): ?array {
            $conversation = $conversations->get((string) $id);
            if (!$conversation) return null;

            $messages = Message::query()
                ->without('attachment')
                ->where('conversation_id', $conversation->id)
                ->latest('created_at')
                ->limit(40)
                ->get(['role', 'content', 'created_at'])
                ->sortBy('created_at')
                ->values()
                ->map(fn (Message $message): array => [
                    'role' => $message->role,
                    'content' => $this->safeText($message->content, 1200),
                    'created_at' => $message->created_at?->toISOString(),
                ])->all();

            return [
                'conversation_id' => (string) $conversation->id,
                'status' => $conversation->status,
                'summary' => $this->safeText($conversation->summary, 1000),
                'messages' => $messages,
            ];
        })->filter()->values()->all();
    }

    private function visualMoments(array $moments): array
    {
        return collect($moments)->map(function (array $moment): array {
            return [
                'moment_id' => $moment['id'] ?? null,
                'event_ids' => array_values($moment['event_ids'] ?? []),
                'event_types' => array_values($moment['event_types'] ?? []),
                'reason' => $moment['reason'] ?? null,
                'replay_timestamp' => $moment['replay_timestamp'] ?? null,
                'relative_timestamp' => $moment['relative_timestamp'] ?? null,
                'viewport' => $moment['viewport'] ?? null,
                'page' => $moment['page'] ?? null,
                'scroll' => $moment['scroll'] ?? null,
                'visible_elements' => array_slice((array) ($moment['visible_elements'] ?? []), 0, 25),
                'visible_text' => $this->safeText($moment['visible_text'] ?? null, 1200),
                'capture_available' => !empty($moment['capture']),
            ];
        })->values()->all();
    }

    private function multimodalContent(array $context, array $rawVisualMoments): array|string
    {
        $content = [[
            'type' => 'text',
            'text' => json_encode([
                'task' => 'Produis une analyse de parcours exploitable dans un dashboard.',
                'output_schema' => [
                    'narrative' => 'string',
                    'facts' => [['text' => 'string', 'confidence' => 'number 0-100', 'evidence' => [['event_id' => 'string|null', 'moment_id' => 'string|null', 'replay_timestamp' => 'number|null']]]],
                    'inferences' => [['text' => 'string', 'confidence' => 'number 0-100', 'evidence' => [['event_id' => 'string|null', 'moment_id' => 'string|null', 'replay_timestamp' => 'number|null']]]],
                    'confidence' => 'number 0-100',
                    'intent_level' => 'low|medium|high',
                    'probable_goal' => 'string|null',
                    'probable_outcome' => 'string|null',
                    'friction_points' => ['string'],
                    'purchase_signals' => ['string'],
                    'unresolved_questions' => ['string'],
                    'recommendations' => ['string'],
                ],
                'context' => $context,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]];

        foreach ($rawVisualMoments as $moment) {
            if (!empty($moment['capture'])) {
                $content[] = [
                    'type' => 'image_url',
                    'image_url' => ['url' => (string) $moment['capture']],
                ];
            }
        }

        if (count($content) === 1) {
            return (string) $content[0]['text'];
        }

        return $content;
    }

    /**
     * Convert the bounded rrweb captures into explicit visual evidence. Raw
     * rrweb chunks never leave the reconstruction service.
     *
     * @param array<int, array<string, mixed>> $moments
     * @return array<int, array<string, mixed>>
     */
    private function visualEvidenceForLlm(VisitorSession $session, array $moments): array
    {
        $evidence = collect($moments)->map(function (array $moment) use ($session): ?array {
            if (empty($moment['capture'])) return null;

            $momentId = (string) ($moment['id'] ?? $moment['moment_id'] ?? '');
            if ($momentId === '') return null;

            return [
                'visual_evidence_id' => 'replay_visual:'.(string) $session->id.':'.$momentId,
                'session_id' => (string) $session->id,
                'moment_id' => $momentId,
                'reason' => $moment['reason'] ?? null,
                'replay_timestamp' => $moment['replay_timestamp'] ?? null,
                'page' => $moment['page'] ?? null,
                'scroll' => $moment['scroll'] ?? null,
                'visible_text' => $this->safeText($moment['visible_text'] ?? null, 1200),
                'visible_elements' => array_slice((array) ($moment['visible_elements'] ?? []), 0, 25),
                'capture' => (string) $moment['capture'],
            ];
        })->filter()->values();

        $limit = max(1, (int) config('visitor-intelligence.ai.max_visual_captures', 3));

        return $evidence->take($limit)->all();
    }

    private function normalizeAnalysis(array $raw, array $validEventIds, array $validMomentIds, array $eventTimestamps, array $momentTimestamps, array $visualMoments): array
    {
        $facts = $this->claims($raw['facts'] ?? [], $validEventIds, $validMomentIds, $eventTimestamps, $momentTimestamps);
        $inferences = $this->claims($raw['inferences'] ?? [], $validEventIds, $validMomentIds, $eventTimestamps, $momentTimestamps);
        $confidence = $this->confidence($raw['confidence'] ?? null);

        return [
            'narrative' => $this->safeText($raw['narrative'] ?? null, 2000),
            'facts' => $facts,
            'inferences' => $inferences,
            'confidence' => $confidence,
            'intent_level' => in_array($raw['intent_level'] ?? null, ['low', 'medium', 'high'], true) ? $raw['intent_level'] : null,
            'probable_goal' => $this->safeText($raw['probable_goal'] ?? null, 300),
            'probable_outcome' => $this->safeText($raw['probable_outcome'] ?? null, 300),
            'friction_points' => $this->stringList($raw['friction_points'] ?? []),
            'purchase_signals' => $this->stringList($raw['purchase_signals'] ?? []),
            'unresolved_questions' => $this->stringList($raw['unresolved_questions'] ?? []),
            'recommendations' => $this->stringList($raw['recommendations'] ?? []),
            'visual_moments' => array_map(static fn (array $moment): array => collect($moment)->except('capture')->all(), $visualMoments),
        ];
    }

    private function claims(mixed $value, array $validEventIds, array $validMomentIds, array $eventTimestamps, array $momentTimestamps): array
    {
        $items = is_array($value) ? $value : (is_string($value) ? [$value] : []);
        return collect($items)->map(function ($item) use ($validEventIds, $validMomentIds, $eventTimestamps, $momentTimestamps): ?array {
            if (is_string($item)) {
                $text = $this->safeText($item, 1000);
                return $text ? ['text' => $text, 'confidence' => null, 'evidence' => []] : null;
            }
            if (!is_array($item)) return null;
            $text = $this->safeText($item['text'] ?? $item['claim'] ?? null, 1000);
            if (!$text) return null;
            return [
                'text' => $text,
                'confidence' => $this->confidence($item['confidence'] ?? null),
                'evidence' => $this->evidenceRefs($item['evidence'] ?? [], $validEventIds, $validMomentIds, $eventTimestamps, $momentTimestamps),
            ];
        })->filter()->values()->take(20)->all();
    }

    private function evidenceRefs(mixed $value, array $validEventIds, array $validMomentIds, array $eventTimestamps, array $momentTimestamps): array
    {
        $items = is_array($value) ? $value : [];
        return collect($items)->map(function ($item) use ($validEventIds, $validMomentIds, $eventTimestamps, $momentTimestamps): ?array {
            if (is_string($item)) $item = ['event_id' => $item];
            if (!is_array($item)) return null;
            $eventId = isset($item['event_id']) && in_array((string) $item['event_id'], $validEventIds, true) ? (string) $item['event_id'] : null;
            $momentId = isset($item['moment_id']) && in_array((string) $item['moment_id'], $validMomentIds, true) ? (string) $item['moment_id'] : null;
            if (!$eventId && !$momentId) return null;
            return [
                'event_id' => $eventId,
                'moment_id' => $momentId,
                'replay_timestamp' => $eventId
                    ? ($eventTimestamps[$eventId] ?? null)
                    : ($momentTimestamps[$momentId] ?? null),
            ];
        })->filter()->unique(fn (array $item): string => ($item['event_id'] ?? '').'|'.($item['moment_id'] ?? ''))->values()->take(10)->all();
    }

    private function stringList(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])
            ->map(fn ($item): ?string => is_scalar($item) ? $this->safeText($item, 500) : null)
            ->filter()->unique()->values()->take(20)->all();
    }

    private function confidence(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, min(100, (int) round((float) $value))) : null;
    }

    private function safeMetadata(array $metadata): array
    {
        $allowed = ['path', 'page_url', 'title', 'depth', 'scroll_x', 'scroll_y', 'page_width', 'page_height', 'viewport_width', 'viewport_height', 'target', 'reason', 'device', 'pointer_type', 'duration_ms', 'active_duration_ms', 'inactivity_count'];
        return collect($metadata)->only($allowed)->map(function ($value) {
            if (is_scalar($value) || $value === null) return is_string($value) ? $this->safeText($value, 300) : $value;
            return null;
        })->filter(fn ($value) => $value !== null)->all();
    }

    private function safeText(mixed $value, int $limit): ?string
    {
        if ($value === null || !is_scalar($value)) return null;
        $text = trim((string) $value);
        if ($text === '') return null;
        $text = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu', '[email]', $text) ?? $text;
        $text = preg_replace('/(?:\+?\d[\d .()\-]{7,}\d)/u', '[phone]', $text) ?? $text;
        return Str::limit(preg_replace('/\s+/u', ' ', $text) ?? $text, $limit, '…');
    }

    private function markFailed(VisitorSessionSummary $summary, string $error): VisitorSessionSummary
    {
        return $summary->forceFill([
            'ai_status' => 'failed',
            'ai_error' => Str::limit($error, 1000, ''),
            'ai_generated_at' => null,
        ])->save() ? $summary->fresh() : $summary;
    }

    private function reportProgress(?callable $progress, int $percent, string $phase, string $message): void
    {
        if ($progress === null) return;
        $progress([
            'progress' => max(0, min(99, $percent)),
            'phase' => $phase,
            'message' => $message,
        ]);
    }
}
