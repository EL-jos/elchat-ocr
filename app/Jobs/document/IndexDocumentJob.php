<?php

namespace App\Jobs\document;

use App\Models\Document;
use App\Models\Site;
use App\Services\IndexService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class IndexDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(protected Document $document, protected Site $site) {}

    public function handle(IndexService $indexService)
    {
        Log::info("DANS INDEXDOCUMENTJOB", [
            "site" => $this->site->id,
            "document" => $this->document->id,
        ]);

        $indexService->indexDocument($this->site, $this->document);

        Log::info("Document indexé: {$this->document->path}");

        // Document indexé, site prêt
        $this->site->update([
            'status' => 'ready',
        ]);
    }

    public function failed(Throwable $e)
    {
        Log::error("IndexDocumentJob failed: {$this->document->path}", [
            'error' => $e->getMessage()
        ]);
    }
}

