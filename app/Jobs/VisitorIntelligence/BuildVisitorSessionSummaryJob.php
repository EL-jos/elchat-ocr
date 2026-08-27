<?php

namespace App\Jobs\VisitorIntelligence;
use romanzipp\QueueMonitor\Traits\IsMonitored;

use App\Models\VisitorSession;
use App\Services\VisitorIntelligence\VisitorIntelligenceSummaryService;
use App\Services\VisitorIntelligence\VisitorIntelligenceRealtimeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class BuildVisitorSessionSummaryJob implements ShouldQueue
{
    use IsMonitored;
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120, 600];

    public function __construct(public readonly string $sessionId)
    {
        $this->onQueue(config('analytics.queue', 'analytics'));
    }

    public function handle(VisitorIntelligenceSummaryService $summaries, VisitorIntelligenceRealtimeService $realtime): void
    {
        $session = VisitorSession::query()->find($this->sessionId);
        if ($session) {
            $summaries->rebuild($session);
            $completedSession = $session->fresh();
            if ($completedSession?->ended_at) {
                // The dashboard receives one completion signal only after the
                // final session event and its derived summary are available.
                $realtime->publish((string) $completedSession->site_id, 'session_completed', [
                    'session_id' => (string) $completedSession->id,
                    'visitor_id' => $completedSession->visitor_id,
                    'completed_at' => $completedSession->ended_at?->toISOString(),
                    'summary_ready' => true,
                ]);
            }
            BuildVisitorSiteOpportunitiesJob::dispatch($session->site_id);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Visitor Intelligence summary job failed.', [
            'session_id' => $this->sessionId, 'error' => $exception?->getMessage(),
        ]);
    }
}
