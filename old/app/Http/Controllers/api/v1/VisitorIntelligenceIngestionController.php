<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\VisitorIntelligence\VisitorIntelligenceEventService;
use App\Services\VisitorIntelligence\VisitorIntelligenceReplayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class VisitorIntelligenceIngestionController extends Controller
{
    public function __construct(
        private readonly VisitorIntelligenceEventService $events,
        private readonly VisitorIntelligenceReplayService $replays,
    )
    {
    }

    public function store(Request $request, Site $site): JsonResponse
    {
        $maxBatch = max(1, (int) config('visitor-intelligence.ingestion_max_batch', 100));
        $data = $request->validate([
            'visitor_uuid' => ['required', 'uuid'],
            'session_id' => ['required', 'string', 'max:100'],
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
        $session = $this->events->ensureSession($site, $visitor, $data['session_id'], $firstEvent, $isNewVisitor);

        foreach ($data['events'] as $event) {
            $this->events->capture($site, $session, $visitor, $event, $request);
        }

        return response()->json([
            'success' => true,
            'accepted' => count($data['events']),
            'visitor_id' => $visitor->id,
            'session_id' => $session->session_key,
        ], 202);
    }

    public function frame(Request $request, Site $site): JsonResponse
    {
        if (!config('visitor-intelligence.frame_capture_enabled', true)) {
            Log::warning('Visitor Intelligence frame capture is disabled.', ['site_id' => $site->id]);
            return response()->json(['success' => false, 'accepted' => false], 202);
        }

        $rawMetadata = $request->input('metadata', []);
        if (is_string($rawMetadata)) {
            $decoded = json_decode($rawMetadata, true);
            $request->merge(['metadata' => is_array($decoded) ? $decoded : []]);
        }

        $maxBytes = max(1024, (int) config('visitor-intelligence.frame_max_bytes', 2097152));
        $data = $request->validate([
            'visitor_uuid' => ['required', 'uuid'],
            'session_id' => ['required', 'string', 'max:100'],
            'event_id' => ['required', 'string', 'max:100'],
            'occurred_at' => ['nullable', 'date'],
            'page_url' => ['nullable', 'url:http,https', 'max:2048'],
            'path' => ['nullable', 'string', 'max:1024'],
            'title' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array', 'max:30'],
            'screenshot' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.(int) ceil($maxBytes / 1024)],
        ]);

        $result = $this->events->captureFrame($site, $request->file('screenshot'), $data, $request);

        return response()->json(['success' => true, 'accepted' => true, ...$result], 202);
    }

    public function replayChunk(Request $request, Site $site): JsonResponse
    {
        $data = $request->validate([
            'visitor_uuid' => ['required', 'uuid'],
            'session_id' => ['required', 'string', 'max:100'],
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
        $session = $this->events->ensureSession($site, $visitor, $data['session_id'], $firstEvent, $isNewVisitor);
        $result = $this->replays->storeChunk($site, $session, $data);

        return response()->json([
            'success' => true,
            ...$result,
            'session_id' => $session->session_key,
        ], 202);
    }
}
