<?php

namespace App\Console\Commands;

use App\Models\AnalyticsEvent;
use App\Models\Conversation;
use App\Models\VisitorIntelligenceAction;
use App\Models\VisitorOpportunity;
use App\Models\VisitorSession;
use App\Models\VisitorSessionReplayChunk;
use App\Models\VisitorSessionSummary;
use App\Services\VisitorIntelligence\VisitorIntelligenceFrameService;
use App\Services\VisitorIntelligence\VisitorIntelligenceRealtimeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneVisitorIntelligenceCommand extends Command
{
    protected $signature = 'visitor-intelligence:prune';
    protected $description = 'Prune every Visitor Intelligence artifact after the fixed two-day retention boundary';

    public function handle(
        VisitorIntelligenceRealtimeService $realtime,
        VisitorIntelligenceFrameService $frames,
    ): int
    {
        $days = 2;
        $cutoff = now()->subDays($days);
        $deleted = 0;
        $affectedSites = [];
        $conversationIds = collect();

        do {
            $sessions = VisitorSession::query()
                ->where(function ($query) use ($cutoff) {
                    $query->where('last_seen_at', '<', $cutoff)
                        // A stale replay is the retention boundary for the
                        // whole journey: never leave a summary/session behind
                        // after its rrweb evidence has expired.
                        ->orWhereIn(
                            'id',
                            VisitorSessionReplayChunk::query()
                                ->where('created_at', '<', $cutoff)
                                ->select('visitor_session_id'),
                        );
                })
                ->limit(500)
                ->get(['id', 'site_id', 'session_key']);
            if ($sessions->isEmpty()) break;
            $affectedSites = array_merge($affectedSites, $sessions->pluck('site_id')->all());

            DB::transaction(function () use ($sessions, &$deleted, $frames, &$conversationIds) {
                foreach ($sessions as $session) {
                    $eventQuery = AnalyticsEvent::query()
                        ->where('site_id', $session->site_id)
                        ->where('session_id', $session->session_key)
                        ->where('source', 'visitor_intelligence');
                    $conversationIds = $conversationIds->merge(
                        (clone $eventQuery)->whereNotNull('conversation_id')->pluck('conversation_id')
                    );
                    // Only browser-originated Visitor Intelligence events are
                    // removed here; shared server business events are not.
                    $frames->deleteForQuery($eventQuery);
                    VisitorSessionReplayChunk::query()->where('visitor_session_id', $session->id)->delete();
                    VisitorSessionSummary::query()->where('visitor_session_id', $session->id)->delete();
                    VisitorIntelligenceAction::query()->where('visitor_session_id', $session->id)->delete();
                    VisitorOpportunity::query()->where('visitor_session_id', $session->id)->delete();
                    $deleted += VisitorSession::query()->whereKey($session->id)->delete();
                }
            });
        } while (true);

        // Clean every orphaned/derived record using the same two-day boundary.
        $conversationIds = $conversationIds->merge(
            AnalyticsEvent::query()
                ->where('source', 'visitor_intelligence')
                ->where('occurred_at', '<', $cutoff)
                ->whereNotNull('conversation_id')
                ->pluck('conversation_id')
        )->filter()->unique()->values();

        $deleted += VisitorSessionSummary::query()->where('generated_at', '<', $cutoff)->delete();
        $deleted += VisitorOpportunity::query()->where('detected_at', '<', $cutoff)->delete();
        $deleted += VisitorIntelligenceAction::query()->where('created_at', '<', $cutoff)->delete();
        $deleted += VisitorSessionReplayChunk::query()->where('created_at', '<', $cutoff)->delete();
        $deleted += $frames->deleteForQuery(AnalyticsEvent::query()
            ->where('source', 'visitor_intelligence')
            ->where('occurred_at', '<', $cutoff));

        foreach ($conversationIds as $conversationId) {
            $stillReferenced = AnalyticsEvent::query()->where('conversation_id', $conversationId)->exists();
            $recentMessage = \App\Models\Message::query()
                ->where('conversation_id', $conversationId)
                ->where('updated_at', '>=', $cutoff)
                ->exists();
            if (!$stillReferenced && !$recentMessage) {
                $deleted += Conversation::query()->whereKey($conversationId)->delete();
            }
        }

        foreach (array_unique($affectedSites) as $siteId) {
            $realtime->publish((string) $siteId, 'retention_pruned', ['retention_days' => $days]);
        }

        $this->components->info("{$deleted} Visitor Intelligence session(s) pruned.");
        return self::SUCCESS;
    }
}
