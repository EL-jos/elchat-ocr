<?php

namespace App\Jobs\WebsiteGrowthAdvisor;

use App\Models\WebsiteGrowthAdvisorAnalysis;
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
        $realtime->publish($siteId, 'analysis_started', [
            'analysis_id' => (string) $analysis->id,
            'status' => 'queued',
            'analysis' => $this->analysisPayload($analysis),
        ]);

        $updated = $advisor->analyze($analysis, function (array $progress) use ($realtime, $siteId, $analysis): void {
            $realtime->publish($siteId, 'analysis_progress', [
                'analysis_id' => (string) $analysis->id,
                'status' => 'running',
                ...$progress,
            ]);
        });

        $realtime->publish($siteId, 'analysis_ready', [
            'analysis_id' => (string) $updated->id,
            'status' => (string) $updated->status,
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
        return $analysis->makeHidden(['data_snapshot'])->toArray();
    }
}
