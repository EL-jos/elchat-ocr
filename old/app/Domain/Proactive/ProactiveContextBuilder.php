<?php

namespace App\Domain\Proactive;

use App\Domain\RAG\RAGToolAdapter;
use App\Models\AnalyticsEvent;
use App\Models\Message;
use App\Models\Proactive\ProactiveMessage;

class ProactiveContextBuilder
{
    public function __construct(private readonly RAGToolAdapter $rag) {}

    public function build(ProactiveMessage $message): array
    {
        $message->loadMissing(['campaign.site', 'campaign.agent', 'campaign.workflow', 'sequence.conversation.memory']);
        $conversation = $message->sequence?->conversation;

        $messages = $conversation
            ? Message::query()->withoutGlobalScopes()->where('conversation_id', $conversation->id)->latest()->limit(12)->get(['id', 'role', 'content', 'created_at'])->reverse()->values()->toArray()
            : [];

        $events = AnalyticsEvent::query()
            ->where('site_id', $message->site_id)
            ->when($message->conversation_id, fn ($query) => $query->where('conversation_id', $message->conversation_id))
            ->when(!$message->conversation_id && $message->visitor_id, fn ($query) => $query->where('visitor_id', $message->visitor_id))
            ->latest('occurred_at')->limit(10)->get(['id', 'event_type', 'value', 'currency', 'metadata', 'occurred_at'])->toArray();

        $query = $message->campaign?->context_query
            ?: collect($messages)->reverse()->firstWhere('role', 'user')['content'] ?? null;
        $knowledge = [];
        if ($query && $message->campaign?->site) {
            $result = $this->rag->search($message->campaign->site, mb_substr((string) $query, 0, 500), 5);
            if ($result->success) $knowledge = $result->data['results'] ?? [];
        }

        return [
            'conversation' => [
                'id' => $conversation?->id,
                'status' => $conversation?->status,
                'summary' => $conversation?->summary,
                'memory' => $conversation?->memory?->memory ?? [],
                'messages' => $messages,
            ],
            'events' => $events,
            'knowledge' => $knowledge,
            'campaign' => [
                'name' => $message->campaign?->name,
                'description' => $message->campaign?->description,
                'context_query' => $message->campaign?->context_query,
            ],
        ];
    }
}
