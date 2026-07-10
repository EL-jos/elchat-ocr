<?php

namespace App\Jobs\sitemap;

use App\Jobs\crawl\CheckCrawlCompletionJob;
use App\Models\Chunk;
use App\Models\CrawlJob;
use App\Models\Page;
use App\Models\Site;
use App\Services\crawl\CrawlService;
use App\Services\IndexService;
use App\Services\lexical\LexicalIndexService;
use App\Services\MercureService;
use App\Services\vector\VectorIndexService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SitemapPageBatchJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(
        public string $siteId,
        public array $crawlJobIds
    ) {}

    public function handle(
        CrawlService $crawlService,
        IndexService $indexService,
        VectorIndexService $vectorIndexService,
        LexicalIndexService $lexicalIndexService,
    ) {
        $site = Site::findOrFail($this->siteId);

        $jobs = CrawlJob::whereIn('id', $this->crawlJobIds)->get();

        $total = count($jobs);
        $done = 0;

        foreach ($jobs as $crawlJob) {

            $done++;

            try {

                if ($crawlService->isExcluded($crawlJob->page_url, $site)) {
                    $crawlJob->update(['status' => 'done']);
                    continue;
                }

                if ($crawlJob->status !== 'pending') {
                    continue;
                }

                $crawlJob->update(['status' => 'processing']);

                $this->notify(
                    'indexing_progress',
                    40 + intval(($done / max($total, 1)) * 50),
                    "Crawl: {$crawlJob->page_url}",
                    false
                );

                $existingPage = Page::where('site_id', $site->id)
                    ->where('url', $crawlJob->page_url)
                    ->first();

                if ($existingPage) {

                    $chunkIds = Chunk::where('page_id', $existingPage->id)
                        ->pluck('id')
                        ->toArray();

                    if (!empty($chunkIds)) {
                        $vectorIndexService->deleteChunksBatch($chunkIds, "chunks_{$this->siteId}");
                        $lexicalIndexService->deleteChunksBatch(chunkIds: $chunkIds, siteId: $this->siteId);
                        Chunk::whereIn('id', $chunkIds)->delete();
                    }

                    $existingPage->delete();
                }

                $page = $crawlService->crawlSinglePage(
                    $site,
                    $crawlJob->page_url,
                    0,
                    $crawlJob->id
                );

                if ($page) {
                    $indexService->indexPage($page, [
                        'source' => 'sitemap',
                        'site_id' => $site->id,
                    ]);
                }

                $crawlJob->update(['status' => 'done']);

            } catch (\Throwable $e) {

                $crawlJob->update([
                    'status' => 'error',
                    'error_message' => $e->getMessage(),
                ]);

                $this->notify(
                    'indexing_warning',
                    0,
                    "Erreur: {$crawlJob->page_url}",
                    false
                );

                Log::error("Erreur crawl sitemap {$crawlJob->page_url}", [
                    'site_id' => $site->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->notify(
            'indexing_progress',
            100,
            'Batch terminé',
            true
        );

        CheckCrawlCompletionJob::dispatch($site->id);
    }

    private function notify(string $type, int $progress, string $message, bool $done = false): void
    {
        app(MercureService::class)->post(
            "site/{$this->siteId}/knowledge/indexing",
            [
                'type' => $type,
                'progress' => $progress,
                'message' => $message,
                'done' => $done,
            ]
        );
    }
}


