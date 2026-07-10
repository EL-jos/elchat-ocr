<?php

namespace App\Jobs\sitemap;

use App\Models\CrawlJob;
use App\Models\Document;
use App\Models\Site;
use App\Services\MercureService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessSitemapJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(
        public string $siteId,
        public string $sitemapDocumentId
    ) {}

    public function handle()
    {
        $site = Site::findOrFail($this->siteId);
        $document = Document::findOrFail($this->sitemapDocumentId);

        $this->notify('indexing_progress', 0, 'Lecture du sitemap...', false);

        $xml = simplexml_load_file(public_path($document->path));

        if (!$xml || !isset($xml->url)) {
            $this->notify('indexing_error', 0, 'Sitemap invalide', true);
            $this->finishIfNoJobs($site);
            return;
        }

        $include = $site->include_pages ?? [];
        $exclude = $site->exclude_pages ?? [];

        $created = 0;
        $total = count($xml->url);
        $processed = 0;

        $this->notify('indexing_progress', 10, 'Analyse des URLs...', false);

        foreach ($xml->url as $node) {

            $url = (string) $node->loc;

            $processed++;

            if ($include && !$this->matches($url, $include)) continue;
            if ($exclude && $this->matches($url, $exclude)) continue;

            $job = CrawlJob::firstOrCreate([
                'site_id' => $site->id,
                'page_url' => $url,
            ], [
                'status' => 'pending',
                'source' => 'sitemap',
            ]);

            if ($job->wasRecentlyCreated) {
                $created++;
            }

            // 📡 progress dynamique parsing
            if ($processed % 10 === 0) {
                $progress = 10 + intval(($processed / max($total, 1)) * 20);

                $this->notify(
                    'indexing_progress',
                    $progress,
                    "Analyse sitemap: {$processed}/{$total}",
                    false
                );
            }
        }

        $this->notify('indexing_progress', 30, 'Création des batches...', false);

        if ($created === 0) {
            $this->notify('indexing_progress', 100, 'Aucune nouvelle URL', true);
            $this->finishIfNoJobs($site);
            return;
        }

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

        $this->notify(
            'indexing_progress',
            40,
            "Batches lancés: {$batches}",
            false
        );
    }

    private function finishIfNoJobs(Site $site): void
    {
        $site->update(['status' => 'ready']);
    }

    private function matches(string $url, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#i';
            if (preg_match($regex, $url)) return true;
        }
        return false;
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


