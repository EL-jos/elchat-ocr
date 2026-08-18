<?php
// app/Jobs/CrawlSiteJob.php

namespace App\Jobs\crawl;

use App\Models\Chunk;
use App\Models\CrawlJob;
use App\Models\Page;
use App\Models\Site;
use App\Services\CrawlService;
use App\Services\IndexService;
use App\Services\lexical\LexicalIndexService;
use App\Services\MercureService;
use App\Services\vector\VectorIndexService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

// Importer le service de crawling
// Importer le service d'indexation

class CrawlSiteJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $timeout = 0;

    public $tries = 1;

    protected string $siteId;

    public function __construct(string $siteId)
    {
        $this->siteId = $siteId;
    }

    public function handle(
        CrawlService $crawlService,
        IndexService $indexService,
        VectorIndexService $vectorIndexService,
        LexicalIndexService $lexicalIndexService,
        MercureService $mercureService,
    ) {

        Log::info('DANS LE JOB', [
            'site_id' => $this->siteId,
        ]);

        $site = Site::findOrFail($this->siteId);

        DB::transaction(function () use ($site) {

            CrawlJob::where('site_id', $site->id)
                ->delete();

        });

        $queueResult = $crawlService->prepareQueue($site);

        $allUrls  = $queueResult['urls'];
        $warnings = $queueResult['warnings'];

        $total = count($allUrls);

        $mercureService->post(
            "site/{$site->id}/knowledge/indexing",
            [
                'type' => 'indexing_info',
                'progress' => 0,
                'message' => "{$total} pages détectées",
                'done' => false,
            ]
        );

        // ⚠️ URLs d'include_pages rejetées (invalides, non résolvables, ou
        // hors domaine) : signalées au client sans bloquer le crawl du reste.
        foreach ($warnings as $warningMessage) {
            $mercureService->post(
                "site/{$site->id}/knowledge/indexing",
                [
                    'type' => 'indexing_warning',
                    'progress' => 0,
                    'message' => $warningMessage,
                    'done' => false,
                ]
            );
        }

        if ($total === 0) {

            $site->update([
                'status' => 'ready'
            ]);

            $mercureService->post(
                "site/{$site->id}/knowledge/indexing",
                [
                    'type' => 'indexing_warning',
                    'progress' => 100,
                    'message' => 'Aucune page trouvée',
                    'done' => true,
                ]
            );

            return;
        }

        foreach ($allUrls as $item) {

            CrawlJob::create([
                'site_id' => $site->id,
                'page_url' => $item['url'],
                'status' => 'pending',
                'source' => 'crawl',
            ]);
        }

        $processed = 0;

        foreach ($allUrls as $item) {

            $url = $item['url'];

            $crawlJob = CrawlJob::where('site_id', $site->id)
                ->where('page_url', $url)
                ->first();

            try {

                $crawlJob->update([
                    'status' => 'processing'
                ]);

                if ($crawlService->isExcluded($url, $site)) {

                    $crawlJob->update([
                        'status' => 'done'
                    ]);

                    $processed++;

                    $this->sendProgress(
                        site: $site,
                        mercureService: $mercureService,
                        processed: $processed,
                        total: $total,
                        message: "Page exclue : {$url}"
                    );

                    continue;
                }

                $existingPage = Page::where('site_id', $site->id)
                    ->where('url', $url)
                    ->first();

                if ($existingPage) {

                    $chunkIds = Chunk::where('page_id', $existingPage->id)
                        ->pluck('id')
                        ->toArray();

                    if (!empty($chunkIds)) {

                        $vectorIndexService->deleteChunksBatch(
                            $chunkIds,
                            collection: "chunks_{$site->id}"
                        );

                        $lexicalIndexService->deleteChunksBatch(
                            chunkIds: $chunkIds,
                            siteId: $site->id
                        );

                        Chunk::whereIn('id', $chunkIds)->delete();
                    }

                    $existingPage->delete();
                }

                $page = $crawlService->crawlSinglePage(
                    $site,
                    $url,
                    0,
                    $crawlJob->id
                );

                if ($page) {

                    $indexService->indexPage(
                        $page,
                        [
                            'source' => 'crawl',
                            'site_id' => $site->id,
                        ]
                    );
                }

                $crawlJob->update([
                    'status' => 'done'
                ]);

            } catch (\Throwable $e) {

                Log::error(
                    "Erreur crawl page",
                    [
                        'url' => $url,
                        'site_id' => $site->id,
                        'error' => $e->getMessage(),
                    ]
                );

                $crawlJob->update([
                    'status' => 'error',
                    'error_message' => $e->getMessage(),
                ]);
            }

            $processed++;

            $this->sendProgress(
                site: $site,
                mercureService: $mercureService,
                processed: $processed,
                total: $total,
                message: "Page traitée : {$url}"
            );
        }

        $site->update([
            'status' => 'ready'
        ]);

        $mercureService->post(
            "site/{$site->id}/knowledge/indexing",
            [
                'type' => 'indexing_progress',
                'progress' => 100,
                'message' => 'Crawl terminé',
                'done' => true,
            ]
        );

        // 👁️ Note : les images détectées pendant ce crawl sont analysées de façon
        // ASYNCHRONE (queue 'vision', ProcessImageOcrJob) et continuent d'arriver
        // en base après ce point. Chaque image indexée émet un événement Mercure
        // séparé de type "image_indexed" sur ce même topic, sans bloquer le crawl.
    }

    private function sendProgress(
        Site $site,
        MercureService $mercureService,
        int $processed,
        int $total,
        string $message
    ) {

        $progress = min(
            99,
            intval(($processed / $total) * 100)
        );

        $mercureService->post(
            "site/{$site->id}/knowledge/indexing",
            [
                'type' => 'indexing_progress',
                'progress' => $progress,
                'message' => $message,
                'done' => false,
            ]
        );
    }

    public function failed(Throwable $e)
    {
        Site::where('id', $this->siteId)
            ->update([
                'status' => 'error'
            ]);

        Log::error(
            "CrawlSiteJob failed",
            [
                'site_id' => $this->siteId,
                'error' => $e->getMessage(),
            ]
        );
    }
}