<?php

namespace App\Jobs\document;

use App\Jobs\Middleware\DocumentOperationLock;
use App\Models\Document;
use App\Models\Site;
use App\Services\DocumentLifecycleService;
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

    public int $tries = 120;
    public int $maxExceptions = 3;
    public int $timeout = 1200;

    public function __construct(
        protected Document $document,
        protected Site $site,
        protected ?int $revision = null,
    ) {
    }

    public function middleware(): array
    {
        return [new DocumentOperationLock($this->document->id)];
    }

    public function handle(IndexService $indexService, DocumentLifecycleService $lifecycle): void
    {
        $document = Document::query()->find($this->document->id);

        if (!$document || (string) $document->documentable_id !== (string) $this->site->id) {
            return;
        }

        if ($this->revision !== null && (int) $document->index_revision !== $this->revision) {
            return;
        }

        Log::info('IndexDocumentJob started', [
            'site_id' => $this->site->id,
            'document_id' => $document->id,
            'revision' => $this->revision,
        ]);

        $document->update(['indexing_status' => 'processing', 'indexing_error' => null]);
        $lifecycle->purgeChunks($document, $this->site);
        $document->refresh();

        if ($this->revision !== null && (int) $document->index_revision !== $this->revision) {
            return;
        }

        $indexService->indexDocument($this->site, $document);

        $document->refresh();
        if ($this->revision === null || (int) $document->index_revision === $this->revision) {
            $document->update([
                'indexing_status' => 'indexed',
                'last_indexed_at' => now(),
                'indexing_error' => null,
            ]);
        }

        $this->site->update(['status' => 'ready']);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('IndexDocumentJob failed', [
            'site_id' => $this->site->id,
            'document_id' => $this->document->id,
            'error' => $exception->getMessage(),
        ]);

        Document::query()->whereKey($this->document->id)->update([
            'indexing_status' => 'failed',
            'indexing_error' => mb_substr($exception->getMessage(), 0, 2000),
        ]);
        Site::query()->whereKey($this->site->id)->update(['status' => 'error']);
    }
}
