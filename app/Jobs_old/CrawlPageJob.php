<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\CrawlService;
use App\Services\IndexService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class CrawlPageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;
    public $tries = 3;

    protected string $siteId;
    protected string $url;
    protected int $depth;

    public function __construct(string $siteId, string $url, int $depth)
    {
        $this->siteId = $siteId;
        $this->url = $url;
        $this->depth = $depth;
    }

    public function handle(CrawlService $crawlService, IndexService $indexService)
    {
        $site = Site::findOrFail($this->siteId);

        $page = $crawlService->crawlSinglePage($site, $this->url, $this->depth);

        if ($page) {
            Log::info("Page créée pour {$this->url} avec ID {$page->id}");
            $indexService->indexPage($page, [
                'source' => 'crawl_single',
                'site_id' => $site->id,
            ]);
        } else {
            Log::warning("Page non créée pour {$this->url}");
        }
    }

    public function failed(Throwable $e)
    {
        Log::error("Erreur CrawlPageJob pour {$this->url}", [
            'site_id' => $this->siteId,
            'error' => $e->getMessage(),
        ]);
    }
}

