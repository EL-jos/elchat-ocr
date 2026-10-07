<?php

namespace App\Jobs\SocialIntelligence;

use App\Models\Social\SocialAnalysis;
use App\SocialIntelligence\Services\SocialIntelligenceAnalysisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunSocialIntelligenceAnalysisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 2;
    public function __construct(public string $analysisId) { $this->onQueue(config('social-intelligence.queue', 'analytics')); }
    public function handle(SocialIntelligenceAnalysisService $service): void
    {
        $analysis = SocialAnalysis::with('site')->find($this->analysisId);
        if ($analysis && in_array($analysis->status, ['queued', 'running'], true)) $service->run($analysis);
    }
}
