<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Models\ResourceEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ResourceEventController extends Controller
{
    /**
     * POST widget/site/{site}/resource-events
     * Accepte un seul événement OU un tableau `events` (batch).
     */
    public function store(Request $request, string $siteId): JsonResponse
    {
        $validated = $request->validate([
            'events' => 'required_without:resource_type|array',
            'events.*.conversation_id' => 'required_with:events|uuid',
            'events.*.message_id' => 'nullable|uuid',
            'events.*.resource_type' => 'required_with:events|string|in:cta,product,page,document,image',
            'events.*.resource_id' => 'nullable|string',
            'events.*.event_type' => 'required_with:events|string|in:impression,click,conversion',
            'events.*.action' => 'nullable|string',
            'events.*.label' => 'nullable|string',
            'events.*.metadata' => 'nullable|array',

            // Cas single-event (rétro-compatible, plus simple pour un clic isolé)
            'conversation_id' => 'required_without:events|uuid',
            'message_id' => 'nullable|uuid',
            'resource_type' => 'required_without:events|string|in:cta,product,page,document,image',
            'resource_id' => 'nullable|string',
            'event_type' => 'required_without:events|string|in:impression,click,conversion',
            'action' => 'nullable|string',
            'label' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $events = $validated['events'] ?? [$validated];

        DB::transaction(function () use ($events, $siteId) {
            foreach ($events as $event) {
                ResourceEvent::create([
                    'id' => (string) Str::uuid(),
                    'site_id' => $siteId,
                    'conversation_id' => $event['conversation_id'],
                    'message_id' => $event['message_id'] ?? null,
                    'resource_type' => $event['resource_type'],
                    'resource_id' => $event['resource_id'] ?? null,
                    'event_type' => $event['event_type'],
                    'action' => $event['action'] ?? null,
                    'label' => $event['label'] ?? null,
                    'metadata' => $event['metadata'] ?? null,
                ]);
            }
        });

        return response()->json(['success' => true], 201);
    }
}
