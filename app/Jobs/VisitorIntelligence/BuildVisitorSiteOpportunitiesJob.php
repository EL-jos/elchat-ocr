<?php

namespace App\Jobs\VisitorIntelligence;
use romanzipp\QueueMonitor\Traits\IsMonitored;

use App\Models\Site;
use App\Services\VisitorIntelligence\VisitorIntelligenceAggregateOpportunityService;
use App\Services\VisitorIntelligence\VisitorIntelligenceRealtimeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BuildVisitorSiteOpportunitiesJob implements ShouldQueue, ShouldBeUnique
{
    use IsMonitored;
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public array $backoff = [60, 300];
    public int $uniqueFor = 300;

    public function __construct(public string $siteId)
    {
        $this->onQueue(config('analytics.queue', 'analytics'));
    }

    public function handle(VisitorIntelligenceAggregateOpportunityService $opportunities, VisitorIntelligenceRealtimeService $realtime): void
    {
        $site = Site::query()->find($this->siteId);
        if ($site) {
            $opportunities->rebuild($site);
            $realtime->publish((string) $site->id, 'opportunities_updated');
        }
    }

    public function uniqueId(): string { return $this->siteId; }
}
