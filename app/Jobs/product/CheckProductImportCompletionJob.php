<?php

namespace App\Jobs\product;
use romanzipp\QueueMonitor\Traits\IsMonitored;

use App\Models\ProductImport;
use App\Services\MercureService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckProductImportCompletionJob implements ShouldQueue
{
    use IsMonitored;
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(public string $importId)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(MercureService $mercureService)
    {
        $import = ProductImport::find($this->importId);

        if (!$import) {
            Log::error('Product import not found', ['import_id' => $this->importId]);
            return;
        }

        // 🔒 Sécurité anti-boucle infinie
        if ($import->started_at->diffInMinutes(now()) > 30) {
            $import->update(['status' => 'failed']);
            $import->document?->update([
                'indexing_status' => 'failed',
                'indexing_error' => 'Le délai maximal de 30 minutes a été dépassé.',
            ]);

            Log::error('Product import timeout', [
                'import_id' => $import->id,
                'site_id' => $import->site_id,
            ]);
            $import->site->update(['status' => 'ready']); // on débloque le site
            return;
        }

        // ⏳ Encore en cours
        if ($import->processed_products < $import->total_products) {
            self::dispatch($import->id)->delay(now()->addSeconds(30));
            return;
        }

        // ✅ Import terminé
        $import->update(['status' => 'completed']);
        $import->document?->update([
            'indexing_status' => 'indexed',
            'last_indexed_at' => now(),
            'indexing_error' => null,
        ]);

        // ✅ Site prêt
        $import->site->update(['status' => 'ready']);

        Log::info('Product import completed', [
            'import_id' => $import->id,
            'site_id' => $import->site_id,
        ]);
        $mercureService->post(
            "site/{$import->site_id}/products/indexing",
            [
                'type' => 'indexing_progress',
                'progress' => 100,
                'message' => 'Import terminé ✅',
                'done' => true
            ]
        );
    }
}
