<?php

namespace App\Jobs\sitemap;
use romanzipp\QueueMonitor\Traits\IsMonitored;

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
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SitemapPageBatchJob implements ShouldQueue
{
    use IsMonitored;
    use Dispatchable, InteractsWithQueue, Queueable;

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

        foreach ($jobs as $crawlJob) {

            try {

                if ($crawlService->isExcluded($crawlJob->page_url, $site)) {
                    $crawlJob->update(['status' => 'done']);
                    $this->notifyProgress($site, "Page exclue : {$crawlJob->page_url}");
                    continue;
                }

                if ($crawlJob->status !== 'pending') {
                    continue;
                }

                $crawlJob->update(['status' => 'processing']);

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

                $this->notifyProgress($site, "Crawl: {$crawlJob->page_url}");

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

                $this->notifyProgress($site, "Crawl: {$crawlJob->page_url}");
            }
        }

        $this->finalizeIfComplete($site);
    }

    /**
     * Progression calculée sur l'ensemble des CrawlJob 'sitemap' du SITE
     * (pas seulement ceux de ce batch), pour rester cohérente même quand
     * plusieurs SitemapPageBatchJob tournent en parallèle sur des lots
     * différents.
     */
    private function notifyProgress(Site $site, string $message): void
    {
        $total = CrawlJob::where('site_id', $site->id)
            ->where('source', 'sitemap')
            ->count();

        $completed = CrawlJob::where('site_id', $site->id)
            ->where('source', 'sitemap')
            ->whereIn('status', ['done', 'error'])
            ->count();

        $progress = 40 + intval(($completed / max($total, 1)) * 50);

        $this->notify('indexing_progress', $progress, $message, false);
    }

    /**
     * Chaque batch tourne indépendamment et ignore l'état des autres. On
     * vérifie donc s'il reste des CrawlJob 'sitemap' non terminés pour le
     * site : s'il en reste, ce n'est pas à CE batch de conclure. Sinon, ce
     * batch est (probablement) le dernier à finir : il tente de marquer le
     * site 'ready'.
     *
     * L'UPDATE conditionnel (WHERE status != 'ready') est atomique côté
     * base : si deux batches terminent quasi simultanément et voient tous
     * les deux 0 restant, un seul verra son UPDATE affecter une ligne — donc
     * un seul enverra le signal de fin. Pas besoin d'un job séparé pour ça.
     */
    private function finalizeIfComplete(Site $site): void
    {
        $remaining = CrawlJob::where('site_id', $site->id)
            ->where('source', 'sitemap')
            ->whereNotIn('status', ['done', 'error'])
            ->count();

        if ($remaining > 0) {
            return;
        }

        $updated = Site::where('id', $site->id)
            ->where('status', '!=', 'ready')
            ->update(['status' => 'ready']);

        if ($updated) {
            $this->notify('indexing_progress', 100, 'Indexation terminée', true);
        }
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
