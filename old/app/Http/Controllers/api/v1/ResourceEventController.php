<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Site;
use App\Services\analytics\AnalyticsEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResourceEventController extends Controller
{
    public function __construct(private readonly AnalyticsEventService $analytics)
    {
    }

    /**
     * POST widget/site/{site}/resource-events
     * Accepte un seul événement OU un tableau `events` (batch).
     */
    public function store(Request $request, Site $site): JsonResponse
    {
        $validated = $request->validate([
            'events' => 'required_without:resource_type|array|max:100',
            'events.*.conversation_id' => 'required_with:events|uuid',
            'events.*.visitor_uuid' => 'required_with:events|uuid',
            'events.*.message_id' => 'nullable|uuid',
            'events.*.resource_type' => 'required_with:events|string|in:cta,product,page,document,image',
            'events.*.resource_id' => 'nullable|string|max:191',
            'events.*.event_type' => 'required_with:events|string|in:impression,click,conversion',
            'events.*.action' => 'nullable|string|max:30',
            'events.*.label' => 'nullable|string|max:255',
            'events.*.metadata' => 'nullable|array',
            'events.*.idempotency_key' => 'nullable|string|max:191',
            'events.*.session_id' => 'nullable|string|max:100',

            // Cas single-event (rétro-compatible, plus simple pour un clic isolé)
            'conversation_id' => 'required_without:events|uuid',
            'visitor_uuid' => 'required_without:events|uuid',
            'message_id' => 'nullable|uuid',
            'resource_type' => 'required_without:events|string|in:cta,product,page,document,image',
            'resource_id' => 'nullable|string|max:191',
            'event_type' => 'required_without:events|string|in:impression,click,conversion',
            'action' => 'nullable|string|max:30',
            'label' => 'nullable|string|max:255',
            'metadata' => 'nullable|array',
            'idempotency_key' => 'nullable|string|max:191',
            'session_id' => 'nullable|string|max:100',
        ]);

        $events = $validated['events'] ?? [$validated];
        $conversationIds = collect($events)->pluck('conversation_id')->unique()->values();
        $conversations = Conversation::query()
            ->where('site_id', $site->id)
            ->whereIn('id', $conversationIds)
            ->with('visitor:id,uuid')
            ->get()
            ->keyBy('id');

        abort_unless($conversations->count() === $conversationIds->count(), 422, 'Conversation invalide pour ce site.');

        foreach ($events as $event) {
            $conversation = $conversations->get($event['conversation_id']);
            abort_unless(
                $conversation->visitor?->uuid === $event['visitor_uuid'],
                422,
                'Visiteur invalide pour cette conversation.',
            );
            $messageId = $event['message_id'] ?? null;

            if ($messageId) {
                abort_unless(
                    Message::where('id', $messageId)->where('conversation_id', $conversation->id)->exists(),
                    422,
                    'Message invalide pour cette conversation.',
                );
            }

            $resourceId = $event['resource_id'] ?? null;
            $legacyType = $event['event_type'];
            $this->analytics->capture(
                site: $site,
                eventType: $this->analytics->canonicalResourceEventType($event['resource_type'], $legacyType),
                context: [
                    'visitor_id' => $conversation->visitor_id,
                    'conversation_id' => $conversation->id,
                    'message_id' => $messageId,
                    'session_id' => $event['session_id'] ?? null,
                    'correlation_id' => $conversation->metadata['session_id']
                        ?? $event['session_id']
                        ?? $conversation->id,
                    'resource_type' => $event['resource_type'],
                    'resource_id' => $resourceId,
                    'source' => 'widget',
                    'channel' => $conversation->metadata['channel'] ?? 'widget',
                    'action' => $event['action'] ?? null,
                    'label' => $event['label'] ?? null,
                ],
                metadata: $event['metadata'] ?? [],
                idempotencyKey: $this->analytics->resourceIdempotencyKey(
                    $site->id,
                    $conversation->id,
                    $messageId,
                    $event['resource_type'],
                    $resourceId,
                    $legacyType,
                ),
            );
        }

        return response()->json(['success' => true], 201);
    }
}
