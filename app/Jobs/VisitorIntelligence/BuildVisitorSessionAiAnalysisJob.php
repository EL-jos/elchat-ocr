<?php

namespace App\Jobs\VisitorIntelligence;

use App\Models\VisitorSession;
use App\Models\WidgetSetting;
use App\Services\VisitorIntelligence\VisitorIntelligenceAiAnalysisService;
use App\Services\VisitorIntelligence\VisitorIntelligenceRealtimeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use romanzipp\QueueMonitor\Traits\IsMonitored;
use Throwable;

class BuildVisitorSessionAiAnalysisJob implements ShouldQueue, ShouldBeUnique
{
    use IsMonitored;
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120, 600];
    public int $uniqueFor = 120;

    public function __construct(
        public readonly string $sessionId,
        public readonly bool $force = false,
    )
    {
        $this->onQueue(config('analytics.queue', 'analytics'));
    }

    public function uniqueId(): string
    {
        return $this->sessionId.':'.($this->isForced() ? 'forced' : 'automatic');
    }

    public function handle(
        VisitorIntelligenceAiAnalysisService $analysis,
        VisitorIntelligenceRealtimeService $realtime,
    ): void
    {
        $session = VisitorSession::query()->find($this->sessionId);
        if (!$session || !$session->ended_at) return;

        $forced = $this->isForced();
        $realtime->publish((string) $session->site_id, 'ai_analysis_started', [
            'session_id' => (string) $session->id,
            'ai_status' => 'pending',
            'progress' => 5,
            'phase' => 'preparation',
            'message' => 'Préparation du parcours à analyser.',
            'forced' => $forced,
        ]);

        $aiEnabled = WidgetSetting::query()
            ->where('site_id', $session->site_id)
            ->value('visitor_intelligence_ai_enabled');

        // Check again here because the job may have been queued before the
        // tenant disabled Visitor Intelligence AI analysis.
        if (!$forced && in_array($aiEnabled, [false, 0, '0'], true)) {
            $session->summary()->update([
                'ai_status' => 'disabled',
                'ai_error' => null,
                'ai_generated_at' => null,
            ]);
            $realtime->publish((string) $session->site_id, 'ai_analysis_ready', [
                'session_id' => (string) $session->id,
                'ai_status' => 'disabled',
                'progress' => 100,
                'phase' => 'disabled',
                'message' => 'L’analyse automatique est désactivée pour ce tenant.',
                'forced' => false,
            ]);
            return;
        }

        $summary = $analysis->analyze($session, $forced, function (array $progress) use ($session, $realtime, $forced): void {
            $realtime->publish((string) $session->site_id, 'ai_analysis_progress', [
                'session_id' => (string) $session->id,
                'ai_status' => 'pending',
                'forced' => $forced,
                ...$progress,
            ]);
        });
        $realtime->publish((string) $session->site_id, 'ai_analysis_ready', [
            'session_id' => (string) $session->id,
            'ai_status' => $summary->ai_status,
            'ai_model' => $summary->ai_model,
            'progress' => 100,
            'phase' => 'completed',
            'message' => $summary->ai_status === 'ready'
                ? 'Analyse IA terminée.'
                : 'L’analyse IA n’a pas pu être produite.',
            'forced' => $forced,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Visitor Intelligence AI analysis job failed.', [
            'session_id' => $this->sessionId,
            'error' => $exception?->getMessage(),
        ]);
    }

    private function isForced(): bool
    {
        // `isset` keeps jobs serialized before the force flag was introduced
        // compatible during a rolling deployment.
        return isset($this->force) && $this->force;
    }
}
