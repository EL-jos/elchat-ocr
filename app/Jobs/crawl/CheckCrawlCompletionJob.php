<?php

namespace App\Jobs\crawl;

use App\Models\CrawlJob;
use App\Models\Site;
use App\Services\IndexService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// CheckCrawlCompletionJob.php
class CheckCrawlCompletionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $siteId;
    public $timeout = 60;
    public $tries = 3;

    public function __construct(string $siteId)
    {
        $this->siteId = $siteId;
    }

    public function handle(IndexService $indexService)
    {
        $site = Site::find($this->siteId);
        if (!$site) return;

        // 1️⃣ Vérifier s'il reste des CrawlJob non terminés
        $remaining = CrawlJob::where('site_id', $site->id)
            ->whereNotIn('status', ['done', 'error'])
            ->count();

        if ($remaining > 0) {
            // Replanifier la vérification
            self::dispatch($this->siteId)->delay(now()->addSeconds(10));
            return;
        }

        // 3️⃣ Marquer le site comme prêt
        $site->update(['status' => 'ready']);

        Log::info("Indexation complète terminée pour le site {$site->id}");
    }
}


