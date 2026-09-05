<?php

namespace App\Console\Commands;

use App\Models\AnalyticsEvent;
use App\Models\VisitorIntelligenceAction;
use App\Models\VisitorOpportunity;
use App\Models\VisitorSession;
use App\Models\VisitorSessionSummary;
use App\Services\VisitorIntelligence\VisitorIntelligenceFrameService;
use App\Services\VisitorIntelligence\VisitorIntelligenceRealtimeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneVisitorIntelligenceCommand extends Command
{
    protected $signature = 'visitor-intelligence:prune {--days=}';
    protected $description = 'Prune Visitor Intelligence sessions and derived data according to tenant retention policy';

    public function handle(
        VisitorIntelligenceRealtimeService $realtime,
        VisitorIntelligenceFrameService $frames,
    ): int
    {
        $days = max(2, (int) ($this->option('days') ?: config('visitor-intelligence.session_retention_days', 2)));
        $cutoff = now()->subDays($days);
        $deleted = 0;
        $affectedSites = [];

        do {
            $sessions = VisitorSession::query()->where('last_seen_at', '<', $cutoff)->limit(500)->get(['id', 'site_id', 'session_key']);
            if ($sessions->isEmpty()) break;
            $affectedSites = array_merge($affectedSites, $sessions->pluck('site_id')->all());

            DB::transaction(function () use ($sessions, &$deleted, $frames) {
                foreach ($sessions as $session) {
                    // Preserve the shared business/conversation analytics stream;
                    // only browser-originated Visitor Intelligence events expire here.
                    $frames->deleteForQuery(AnalyticsEvent::query()
                        ->where('site_id', $session->site_id)
                        ->where('session_id', $session->session_key)
                        ->where('source', 'visitor_intelligence'));
                    VisitorIntelligenceAction::query()->where('visitor_session_id', $session->id)->delete();
                    VisitorOpportunity::query()->where('visitor_session_id', $session->id)->delete();
                    $deleted += VisitorSession::query()->whereKey($session->id)->delete();
                }
            });
        } while (true);

        // Clean derived records that can outlive a session (for example an
        // opportunity created by an approved rule without a session FK).
        $summaryCutoff = now()->subDays(max($days, (int) config('visitor-intelligence.summary_retention_days', 365)));
        $deleted += VisitorSessionSummary::query()->where('generated_at', '<', $summaryCutoff)->delete();
        $deleted += VisitorOpportunity::query()->where('detected_at', '<', $cutoff)->delete();
        $deleted += VisitorIntelligenceAction::query()->where('created_at', '<', $cutoff)->delete();
        $deleted += $frames->deleteForQuery(AnalyticsEvent::query()
            ->where('source', 'visitor_intelligence')
            ->where('occurred_at', '<', $cutoff));

        foreach (array_unique($affectedSites) as $siteId) {
            $realtime->publish((string) $siteId, 'retention_pruned', ['retention_days' => $days]);
        }

        $this->components->info("{$deleted} Visitor Intelligence session(s) pruned.");
        return self::SUCCESS;
    }
}
