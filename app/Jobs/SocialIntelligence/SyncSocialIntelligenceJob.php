<?php

namespace App\Jobs\SocialIntelligence;

use App\Models\Site;
use App\SocialIntelligence\Services\SocialIntelligenceSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncSocialIntelligenceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 3;
    public function __construct(public string $siteId, public ?array $sources = null) { $this->onQueue(config('social-intelligence.queue', 'analytics')); }
    public function handle(SocialIntelligenceSyncService $service): void
    {
        $site = Site::find($this->siteId);
        if ($site) $service->syncSite($site, $this->sources);
    }
}
