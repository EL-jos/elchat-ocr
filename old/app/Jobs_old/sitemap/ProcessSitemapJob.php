<?php

namespace App\Jobs\sitemap;

use App\Jobs\Middleware\DocumentOperationLock;
use App\Models\CrawlJob;
use App\Models\Document;
use App\Models\Site;
use App\Services\crawl\CrawlService;
use App\Services\MercureService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

class ProcessSitemapJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 120;
    public int $maxExceptions = 3;
    public int $timeout = 600;

    public function __construct(
        public string $siteId,
        public string $sitemapDocumentId,
        public ?int $revision = null,
    ) {
    }

    public function middleware(): array
    {
        return [new DocumentOperationLock($this->sitemapDocumentId)];
    }

    public function handle(CrawlService $crawlService): void
    {
        $site = Site::findOrFail($this->siteId);
        $document = Document::findOrFail($this->sitemapDocumentId);

        if ((string) $document->documentable_id !== (string) $site->id) {
            return;
        }

        if ($this->revision !== null && (int) $document->index_revision !== $this->revision) {
            return;
        }

        $document->update(['indexing_status' => 'processing', 'indexing_error' => null]);
        $this->notify('indexing_progress', 0, 'Lecture du sitemap...', false);

        $previousErrors = libxml_use_internal_errors(true);
        $xml = simplexml_load_file(public_path($document->path), 'SimpleXMLElement', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);

        if (!$xml || !isset($xml->url)) {
            $document->update([
                'indexing_status' => 'failed',
                'indexing_error' => 'Le sitemap ne contient aucune URL valide.',
            ]);
            $this->notify('indexing_error', 0, 'Sitemap invalide', true);
            $this->finishIfNoJobs($site);
            return;
        }

        $created = 0;
        $total = count($xml->url);
        $processed = 0;

        $this->notify('indexing_progress', 10, 'Analyse des URLs...', false);

        foreach ($xml->url as $node) {
            $rawUrl = (string) $node->loc;
            $processed++;
            $url = $crawlService->normalizeUrl($rawUrl) ?: $rawUrl;

            if (!$crawlService->isIncluded($url, $site)) {
                continue;
            }
            if ($crawlService->isExcluded($url, $site)) {
                continue;
            }

            $job = CrawlJob::updateOrCreate([
                'site_id' => $site->id,
                'page_url' => $url,
            ], [
                'status' => 'pending',
                'source' => 'sitemap',
                'source_document_id' => $document->id,
            ]);

            // Un remplacement ou une réindexation de sitemap doit aussi recrawler
            // les URLs déjà connues, pas uniquement les nouvelles.
            $created++;

            if ($processed % 10 === 0) {
                $progress = 10 + intval(($processed / max($total, 1)) * 20);
                $this->notify('indexing_progress', $progress, "Analyse sitemap: {$processed}/{$total}", false);
            }
        }

        $document->update([
            'indexing_status' => 'indexed',
            'last_indexed_at' => now(),
            'indexing_error' => null,
        ]);

        $this->notify('indexing_progress', 30, 'Création des lots...', false);

        if ($created === 0) {
            $this->notify('indexing_progress', 100, 'Aucune nouvelle URL', true);
            $this->finishIfNoJobs($site);
            return;
        }

        $this->dispatchBatches($site);
    }

    public function failed(Throwable $exception): void
    {
        Document::query()->whereKey($this->sitemapDocumentId)->update([
            'indexing_status' => 'failed',
            'indexing_error' => mb_substr($exception->getMessage(), 0, 2000),
        ]);
    }

    private function dispatchBatches(Site $site): void
    {
        $batchSize = 5;
        $batches = 0;

        CrawlJob::where('site_id', $site->id)
            ->where('source', 'sitemap')
            ->where('status', 'pending')
            ->get()
            ->chunk($batchSize)
            ->each(function ($chunk) use ($site, &$batches) {
                $batches++;
                SitemapPageBatchJob::dispatch(
                    siteId: $site->id,
                    crawlJobIds: $chunk->pluck('id')->toArray()
                );
            });

        $this->notify('indexing_progress', 40, "Lots lancés: {$batches}", false);
    }

    private function finishIfNoJobs(Site $site): void
    {
        $site->update(['status' => 'ready']);
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
