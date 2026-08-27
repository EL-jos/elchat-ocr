<?php

namespace App\Jobs\crawl;
use romanzipp\QueueMonitor\Traits\IsMonitored;

use App\Models\Chunk;
use App\Models\CrawlJob;
use App\Models\Page;
use App\Models\Site;
use App\Services\CrawlService;
use App\Services\IndexService;
use App\Services\lexical\LexicalIndexService;
use App\Services\MercureService;
use App\Services\vector\VectorIndexService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

// CrawlPageBatchJob.php
class CrawlPageBatchJob implements ShouldQueue
{
    use IsMonitored;
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;
    public $tries = 3;

    protected string $siteId;
    protected array $urls; // tableau d'URLs

    public function __construct(string $siteId, array $urls)
    {
        $this->siteId = $siteId;
        $this->urls = $urls;
    }

    public function handle(
        CrawlService $crawlService,
        IndexService $indexService,
        VectorIndexService $vectorIndexService,
        LexicalIndexService $lexicalIndexService,
        MercureService $mercureService,
    )
    {
        $site = Site::findOrFail($this->siteId);

        foreach ($this->urls as $url) {
            $crawlJob = CrawlJob::where('site_id', $site->id)
                ->where('page_url', $url)
                ->where('status', 'pending')
                ->first();

            if (!$crawlJob) continue;

            $updated = CrawlJob::where('id', $crawlJob->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'processing'
                ]);

            if ($updated === 0) {
                continue;
            }

            if ($crawlService->isExcluded($crawlJob->page_url, $site)) {
                $crawlJob->update(['status' => 'done']);
                $mercureService->post(
                    "site/{$site->id}/knowledge/indexing",
                    [
                        'type' => 'indexing_warning',
                        'progress' => null,
                        'message' => "Page exclue : {$url}",
                        'done' => false
                    ]
                );

                $this->sendProgress(
                    $site,
                    $mercureService
                );
                continue;
            }

            //$crawlJob->update(['status' => 'processing']);

            try {
                // 🔥 RECRAWL SAFE — suppression ancienne page + chunks
                $existingPage = Page::where('site_id', $site->id)
                    ->where('url', $crawlJob->page_url)
                    ->first();

                if ($existingPage) {

                    $chunkIds = Chunk::where('page_id', $existingPage->id)
                        ->pluck('id')
                        ->toArray();

                    if (!empty($chunkIds)) {
                        $vectorIndexService->deleteChunksBatch($chunkIds, collection: "chunks_{$site->id}");
                        $lexicalIndexService->deleteChunksBatch(chunkIds: $chunkIds, siteId: $site->id);
                        Chunk::whereIn('id', $chunkIds)->delete();
                    }

                    $existingPage->delete();
                }

                $page = $crawlService->crawlSinglePage($site, $url, 0, $crawlJob->id);

                if ($page) {
                    Log::warning("AGE CREEE", [
                        'age' => $page->id,
                        'title' => $page->title
                    ]);

                    Log::warning("DEBUT DE L'INDEXATION DE LA AGE CREEE", [
                        'age' => $page->id,
                        'title' => $page->title
                    ]);
                    // Index uniquement les pages
                    $indexService->indexPage($page, [
                        'source' => 'crawl',
                        'site_id' => $site->id,
                    ]);
                    Log::warning("FIN DE L'INDEXATION DE LA AGE CREEE", [
                        'age' => $page->id,
                        'title' => $page->title
                    ]);
                }

                $crawlJob->update(['status' => 'done']);
                $this->sendProgress(
                    $site,
                    $mercureService,
                    "Page indexée : {$url}"
                );
            } catch (\Throwable $e) {
                $crawlJob->update([
                    'status' => 'error',
                    'error_message' => $e->getMessage(),
                ]);
                Log::error("Erreur crawl page {$url}", ['site_id' => $site->id, 'error' => $e->getMessage()]);
                $mercureService->post(
                    "site/{$site->id}/knowledge/indexing",
                    [
                        'type' => 'indexing_error',
                        'progress' => null,
                        'message' => "Erreur sur {$url} : {$e->getMessage()}",
                        'done' => false
                    ]
                );

                $this->sendProgress(
                    $site,
                    $mercureService
                );
            }
        }

        // Vérifier si le site est terminé
        //CheckCrawlCompletionJob::dispatch($site->id);

        Log::info("Batch dispatché pour site {$this->siteId}, pages: " . count($this->urls));
    }
    public function failed(Throwable $e)
    {
        Log::error("CrawlPageBatchJob échoué pour site {$this->siteId}", [
            'error' => $e->getMessage(),
            'urls' => $this->urls,
        ]);
        CrawlJob::where('site_id', $this->siteId)
            ->whereIn('page_url', $this->urls)
            ->where('status', 'processing')
            ->update([
                'status' => 'error',
                'error_message' => 'Batch failed'
            ]);
    }
    private function sendProgress(
        Site $site,
        MercureService $mercureService,
        string $message = null
    ) {

        if ($site->status === 'ready') {
            return;
        }

        $total = CrawlJob::where('site_id', $site->id)->count();

        $done = CrawlJob::where('site_id', $site->id)
            ->whereIn('status', ['done', 'error'])
            ->count();

        $progress = $total > 0
            ? intval(($done / $total) * 100)
            : 0;

        $isFinished = $done >= $total;

        // IMPORTANT :
        // done=true UNIQUEMENT la première fois
        $shouldSendDoneEvent = false;

        $site->refresh();
        if ($isFinished) {

            $updated = Site::where('id', $site->id)
                ->where('status', '!=', 'ready')
                ->update([
                    'status' => 'ready'
                ]);

            $shouldSendDoneEvent = $updated > 0;
        }

        // Si déjà terminé, ne plus envoyer d'event final
        if ($isFinished && !$shouldSendDoneEvent && $progress >= 100) {
            return;
        }

        $mercureService->post(
            "site/{$site->id}/knowledge/indexing",
            [
                'type' => $isFinished
                    ? 'indexing_info'
                    : 'indexing_progress',

                'progress' => $progress,

                'message' => $message ?? (
                    $isFinished
                        ? 'Crawl terminé'
                        : "Indexation {$done}/{$total}"
                    ),

                'done' => $shouldSendDoneEvent
            ]
        );
    }
}
