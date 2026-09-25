<?php

namespace App\Jobs\WebsiteGrowthAdvisor;

use App\Models\WebsiteGrowthAdvisorAnalysis;
use App\Services\WebsiteGrowthAdvisor\WebsiteGrowthAdvisorConfigurationService;
use App\Services\WebsiteGrowthAdvisor\WebsiteGrowthAdvisorService;
use App\Services\WebsiteGrowthAdvisor\WebsiteGrowthAdvisorRealtimeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunWebsiteGrowthAdvisorAnalysisJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public array $backoff = [60, 300];
    public int $uniqueFor = 300;

    public function __construct(public readonly string $analysisId)
    {
        $this->onQueue(config('website-growth-advisor.queue', 'website-growth-advisor'));
    }

    public function uniqueId(): string
    {
        return $this->analysisId;
    }

    public function handle(WebsiteGrowthAdvisorService $advisor, WebsiteGrowthAdvisorRealtimeService $realtime): void
    {
        $analysis = WebsiteGrowthAdvisorAnalysis::query()->find($this->analysisId);
        if (! $analysis || $analysis->status === 'ready') return;

        $siteId = (string) $analysis->site_id;
        $this->persistProgress($analysis, 5, 'running', 'preparation', 'Préparation de l’analyse Growth Advisor.');
        $realtime->publish($siteId, 'analysis_started', [
            'analysis_id' => (string) $analysis->id,
            'status' => 'running',
            'progress' => 5,
            'phase' => 'preparation',
            'message' => 'Préparation de l’analyse Growth Advisor.',
            'analysis' => $this->analysisPayload($analysis),
        ]);

        $updated = $advisor->analyze($analysis, function (array $progress) use ($realtime, $siteId, $analysis): void {
            $progressValue = (int) ($progress['progress'] ?? 0);
            $progressStatus = $progressValue >= 100 ? 'ready' : 'running';
            $this->persistProgress(
                $analysis,
                $progressValue,
                $progressStatus,
                (string) ($progress['phase'] ?? 'processing'),
                (string) ($progress['message'] ?? 'Analyse en cours.'),
            );
            $realtime->publish($siteId, 'analysis_progress', [
                'analysis_id' => (string) $analysis->id,
                'status' => $progressStatus,
                ...$progress,
            ]);
        });

        // Keep the terminal state even if a final progress callback was
        // emitted immediately before this notification.
        $realtime->publish($siteId, 'analysis_ready', [
            'analysis_id' => (string) $updated->id,
            'status' => 'ready',
            'analysis' => $this->analysisPayload($updated),
            'progress' => 100,
            'phase' => 'completed',
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $analysis = WebsiteGrowthAdvisorAnalysis::query()->whereKey($this->analysisId)->first();
        if (! $analysis) {
            return;
        }

        $analysis->update([
            'status' => 'failed',
            'phase' => 'failed',
            'progress_message' => 'L’analyse Growth Advisor a échoué.',
            'error_message' => $exception?->getMessage() ?: 'website_growth_advisor_job_failed',
            'completed_at' => now(),
        ]);

        app(WebsiteGrowthAdvisorRealtimeService::class)->publish((string) $analysis->site_id, 'analysis_failed', [
            'analysis_id' => (string) $analysis->id,
            'status' => 'failed',
            'analysis' => $this->analysisPayload($analysis->fresh()),
            'error_message' => $analysis->error_message,
        ]);

        Log::error('Website Growth Advisor analysis job failed.', [
            'analysis_id' => $this->analysisId,
            'error' => $exception?->getMessage(),
        ]);
    }

    /** @return array<string, mixed> */
    private function analysisPayload(WebsiteGrowthAdvisorAnalysis $analysis): array
    {
        $payload = $analysis->makeHidden(['data_snapshot'])->toArray();
        $snapshot = is_array($analysis->configuration_snapshot) ? $analysis->configuration_snapshot : [];
        $mode = $snapshot['configuration_mode'] ?? null;
        $presetKey = $mode === 'custom'
            ? null
            : (is_string($snapshot['preset_key'] ?? null) ? $snapshot['preset_key'] : ($snapshot['primary_objective'] ?? null));
        $preset = is_string($presetKey)
            ? (app(WebsiteGrowthAdvisorConfigurationService::class)->presets()[$presetKey] ?? null)
            : null;
        $payload['configuration_mode'] = $mode;
        $payload['configuration_preset_key'] = $preset ? $presetKey : null;
        $payload['configuration_preset_label'] = $mode === 'custom'
            ? 'Configuration personnalisée'
            : ($preset['label'] ?? 'Configuration précédente');
        $payload['visualization'] = $analysis->visualizationData();
        return $payload;
    }

    private function persistProgress(
        WebsiteGrowthAdvisorAnalysis $analysis,
        int $progress,
        string $status,
        string $phase,
        string $message,
    ): void {
        $analysis->forceFill([
            'status' => $status,
            'progress' => max(0, min(100, $progress)),
            'phase' => $phase,
            'progress_message' => $message,
        ])->save();
    }
}
