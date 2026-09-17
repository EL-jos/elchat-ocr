<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Jobs\VisitorIntelligence\BuildVisitorSessionAiAnalysisJob;
use App\Jobs\VisitorIntelligence\ScoreVisitorSessionForBotActivityJob;
use App\Models\Site;
use App\Services\VisitorIntelligence\VisitorIntelligenceEventService;
use App\Services\VisitorIntelligence\VisitorIntelligenceReplayService;
use App\Services\VisitorIntelligence\VisitorSessionLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VisitorIntelligenceIngestionController extends Controller
{
    public function __construct(
        private readonly VisitorIntelligenceEventService $events,
        private readonly VisitorIntelligenceReplayService $replays,
        private readonly VisitorSessionLocationService $locations,
    )
    {
    }

    public function store(Request $request, Site $site): JsonResponse
    {
        $maxBatch = max(1, (int) config('visitor-intelligence.ingestion_max_batch', 100));
        $data = $request->validate([
            'visitor_uuid' => ['required', 'uuid'],
            'session_id' => ['required', 'string', 'max:100'],
            'client_signals' => ['nullable', 'array', 'max:20'],
            'client_signals.webdriver' => ['nullable', 'boolean'],
            'client_signals.plugins_count' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'client_signals.languages' => ['nullable', 'string', 'max:512'],
            'client_signals.hardware_concurrency' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'client_signals.device_memory' => ['nullable', 'numeric', 'min:0', 'max:1024'],
            'client_signals.max_touch_points' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'client_signals.has_chrome_runtime' => ['nullable', 'boolean'],
            'client_signals.webgl_renderer' => ['nullable', 'string', 'max:512'],
            'client_signals.chrome_diff_w' => ['nullable', 'integer', 'between:-10000,10000'],
            'client_signals.chrome_diff_h' => ['nullable', 'integer', 'between:-10000,10000'],
            'client_signals.screen_w' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'client_signals.screen_h' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'events' => ['required', 'array', 'min:1', 'max:'.$maxBatch],
            'events.*.event_id' => ['nullable', 'string', 'max:100'],
            'events.*.event_type' => ['required', 'string', Rule::in(VisitorIntelligenceEventService::browserEventTypes())],
            'events.*.occurred_at' => ['nullable', 'date'],
            'events.*.page_url' => ['nullable', 'url:http,https', 'max:2048'],
            'events.*.path' => ['nullable', 'string', 'max:1024'],
            'events.*.title' => ['nullable', 'string', 'max:255'],
            'events.*.resource_type' => ['nullable', 'string', 'max:64'],
            'events.*.resource_id' => ['nullable', 'string', 'max:191'],
            'events.*.label' => ['nullable', 'string', 'max:255'],
            'events.*.metadata' => ['nullable', 'array', 'max:30'],
            'events.*.idempotency_key' => ['nullable', 'string', 'max:191'],
        ]);

        [$visitor, $isNewVisitor] = $this->events->resolveVisitor($site, $data['visitor_uuid'], $request);
        $firstEvent = $data['events'][0];
        $session = $this->events->ensureSession($site, $visitor, $data['session_id'], $firstEvent, $isNewVisitor, $data['client_signals'] ?? []);
        $this->locations->dispatchIfNeeded($session, $request->ip());

        foreach ($data['events'] as $event) {
            $this->events->capture($site, $session, $visitor, $event, $request);
        }
        if (config('visitor-intelligence.bot_detection.enabled', false) && !empty($data['client_signals'])) {
            ScoreVisitorSessionForBotActivityJob::dispatch((string) $session->id)
                ->delay(now()->addSeconds(max(0, (int) config('visitor-intelligence.bot_detection.score_delay_seconds', 20))));
        }

        return response()->json([
            'success' => true,
            'accepted' => count($data['events']),
            'visitor_id' => $visitor->id,
            'session_id' => $session->session_key,
        ], 202);
    }

    public function replayChunk(Request $request, Site $site): JsonResponse
    {
        $data = $request->validate([
            'visitor_uuid' => ['required', 'uuid'],
            'session_id' => ['required', 'string', 'max:100'],
            'client_signals' => ['nullable', 'array', 'max:20'],
            'client_signals.webdriver' => ['nullable', 'boolean'],
            'client_signals.plugins_count' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'client_signals.languages' => ['nullable', 'string', 'max:512'],
            'client_signals.hardware_concurrency' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'client_signals.device_memory' => ['nullable', 'numeric', 'min:0', 'max:1024'],
            'client_signals.max_touch_points' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'client_signals.has_chrome_runtime' => ['nullable', 'boolean'],
            'client_signals.webgl_renderer' => ['nullable', 'string', 'max:512'],
            'client_signals.chrome_diff_w' => ['nullable', 'integer', 'between:-10000,10000'],
            'client_signals.chrome_diff_h' => ['nullable', 'integer', 'between:-10000,10000'],
            'client_signals.screen_w' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'client_signals.screen_h' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'chunk_index' => ['required', 'integer', 'between:0,1000000'],
            'rrweb_version' => ['nullable', 'string', 'max:16'],
            'occurred_at' => ['nullable', 'date'],
            'page_url' => ['nullable', 'url:http,https', 'max:2048'],
            'path' => ['nullable', 'string', 'max:1024'],
            'title' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array', 'max:30'],
            'events' => [
                'required',
                'array',
                'min:1',
                'max:'.max(1, (int) config('visitor-intelligence.replay_chunk_max_events', 500)),
            ],
            'events.*' => ['required', 'array'],
        ]);

        [$visitor, $isNewVisitor] = $this->events->resolveVisitor($site, $data['visitor_uuid'], $request);
        $firstEvent = [
            'occurred_at' => $data['occurred_at'] ?? now()->toISOString(),
            'page_url' => $data['page_url'] ?? null,
            'path' => $data['path'] ?? null,
            'title' => $data['title'] ?? null,
            'metadata' => [
                ...($data['metadata'] ?? []),
                'page_url' => $data['page_url'] ?? null,
                'path' => $data['path'] ?? null,
                'title' => $data['title'] ?? null,
                'device' => $data['metadata']['device'] ?? null,
                'viewport_width' => $data['metadata']['viewport_width'] ?? null,
                'viewport_height' => $data['metadata']['viewport_height'] ?? null,
            ],
        ];
        $session = $this->events->ensureSession($site, $visitor, $data['session_id'], $firstEvent, $isNewVisitor, $data['client_signals'] ?? []);
        $this->locations->dispatchIfNeeded($session, $request->ip());
        $result = $this->replays->storeChunk($site, $session, $data);
        if (config('visitor-intelligence.bot_detection.enabled', false) && (!$result['duplicate'] || !empty($data['client_signals']))) {
            ScoreVisitorSessionForBotActivityJob::dispatch((string) $session->id)
                ->delay(now()->addSeconds(max(0, (int) config('visitor-intelligence.bot_detection.score_delay_seconds', 20))));
        }
        if ($session->fresh()?->ended_at) {
            BuildVisitorSessionAiAnalysisJob::dispatch((string) $session->id)
                ->delay(now()->addSeconds(max(0, (int) config('visitor-intelligence.ai.analysis_delay_seconds', 20))));
        }

        return response()->json([
            'success' => true,
            ...$result,
            'session_id' => $session->session_key,
        ], 202);
    }
}
