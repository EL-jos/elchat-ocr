<?php

namespace App\Jobs\vision;
use romanzipp\QueueMonitor\Traits\IsMonitored;

use App\Models\Vision\PageImage;
use App\Services\IndexService;
use App\Services\MercureService;
use App\Services\vision\ImageVisionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessImageOcrJob implements ShouldQueue
{
    use IsMonitored;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $tries = 3;

    public $backoff = [10, 30, 90];

    public $timeout = 120;

    public function __construct(protected string $pageImageId)
    {
        $this->onQueue(config('vision.queue', 'vision'));
    }

    public function handle(
        ImageVisionService $visionService,
        IndexService $indexService,
        MercureService $mercureService,
    ): void {

        $pageImage = PageImage::find($this->pageImageId);

        if (!$pageImage) {
            return;
        }

        // Idempotence : image déjà traitée (ex: retry tardif après succès)
        if (in_array($pageImage->status, ['done', 'skipped'], true)) {
            return;
        }

        $pageImage->update(['status' => 'processing']);

        try {
            $result = $visionService->analyzeImageUrl($pageImage);

            if ($result === null) {
                $pageImage->update([
                    'status' => 'skipped',
                    'error_message' => null,
                ]);
                return;
            }

            $pageImage->update([
                'status' => 'done',
                'description' => $result['description'],
                'ocr_text' => $result['ocr_text'],
                'content_hash' => $result['content_hash'],
                'width' => $result['width'] ?? $pageImage->width,
                'height' => $result['height'] ?? $pageImage->height,
                'error_message' => null,
            ]);

            // Indexation dans le pipeline RAG (chunk texte fusionné + metadata image_url)
            $indexService->indexImageChunk($pageImage->fresh());

            $mercureService->post(
                "site/{$pageImage->site_id}/knowledge/indexing",
                [
                    'type' => 'image_indexed',
                    'page_id' => $pageImage->page_id,
                    'image_url' => $pageImage->url,
                    'message' => 'Image analysée et indexée',
                    'done' => false,
                ]
            );

        } catch (Throwable $e) {

            Log::error('ProcessImageOcrJob error', [
                'page_image_id' => $this->pageImageId,
                'url' => $pageImage->url ?? null,
                'error' => $e->getMessage(),
            ]);

            $pageImage->update([
                'status' => 'error',
                'error_message' => substr($e->getMessage(), 0, 500),
            ]);

            throw $e; // laisse Laravel gérer le retry avec backoff
        }
    }

    public function failed(Throwable $e): void
    {
        PageImage::where('id', $this->pageImageId)->update([
            'status' => 'error',
            'error_message' => substr($e->getMessage(), 0, 500),
        ]);

        Log::error('ProcessImageOcrJob failed definitively', [
            'page_image_id' => $this->pageImageId,
            'error' => $e->getMessage(),
        ]);
    }
}
