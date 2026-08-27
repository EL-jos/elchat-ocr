<?php

namespace App\Jobs\product;

use App\Mappers\ProductFileParser;
use App\Mappers\ProductMapper;
use App\Models\Document;
use App\Models\ProductImport;
use App\Models\Site;
use App\Services\MercureService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProductImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;

    public function __construct(
        public Document $document,
        public array $mapping,
        public Site $site
    ) {}

    public function handle(MercureService $mercureService)
    {
        $this->document->update(['indexing_status' => 'processing', 'indexing_error' => null]);

        Log::info("🚀 ProductImportJob démarré pour site {$this->site->id}");

        $this->site->update(['status' => 'indexing']);

        try {
            $mercureService->post("site/{$this->site->id}/products/indexing", [
                'type' => 'indexing_progress',
                'progress' => 0,
                'message' => 'Lecture du fichier...',
                'done' => false
            ]);
            // 🔹 1. Parse fichier
            $rows = ProductFileParser::parse($this->document);

            if (empty($rows)) {
                $this->document->update([
                    'indexing_status' => 'failed',
                    'indexing_error' => 'Aucun produit exploitable trouvé dans le fichier.',
                ]);
                Log::warning("⚠️ Aucun produit trouvé");
                $this->site->update(['status' => 'ready']);
                return;
            }
            $mercureService->post("site/{$this->site->id}/products/indexing", [
                'type' => 'indexing_progress',
                'progress' => 5,
                'message' => 'Fichier analysé, mapping en cours...',
                'done' => false
            ]);

            // 🔹 2. Mapping
            $products = ProductMapper::map($rows, $this->mapping);

            if (empty($products)) {
                $this->document->update([
                    'indexing_status' => 'failed',
                    'indexing_error' => 'Le mapping produit ne contient aucune donnée exploitable.',
                ]);
                Log::warning("⚠️ Mapping vide");
                $this->site->update(['status' => 'ready']);
                return;
            }
            $mercureService->post("site/{$this->site->id}/products/indexing", [
                'type' => 'indexing_progress',
                'progress' => 10,
                'message' => 'Produits prêts à être importés...',
                'done' => false
            ]);

            // 🔹 3. Empêcher double import
            $existing = ProductImport::where('document_id', $this->document->id)
                ->where('status', 'processing')
                ->first();

            if ($existing) {
                Log::warning("⚠️ Import déjà en cours pour ce document");
                return;
            }

            // 🔹 4. Création import (TRACKING)
            $import = ProductImport::create([
                'site_id' => $this->site->id,
                'document_id' => $this->document->id,
                'total_products' => count($products),
                'processed_products' => 0,
                'status' => 'processing',
                'started_at' => now()
            ]);

            // 🔹 5. Dispatch batchs
            collect($products)
                ->chunk(100)
                ->each(function ($batch) use ($import) {

                    IndexProductBatchJob::dispatch(
                        $batch->toArray(),
                        $this->document,
                        $import->id,
                        $import->site->id,
                    );
                });
            $mercureService->post("site/{$this->site->id}/products/indexing", [
                'type' => 'indexing_progress',
                'progress' => 10,
                'message' => 'Import en cours...',
                'done' => false
            ]);

            // 🔹 6. Check async completion
            CheckProductImportCompletionJob::dispatch($import->id)
                ->delay(now()->addSeconds(30));

            Log::info("📦 Import lancé avec ID {$import->id}");

        } catch (\Throwable $e) {
            $this->site->update(['status' => 'error']);
            $this->document->update([
                'indexing_status' => 'failed',
                'indexing_error' => mb_substr($e->getMessage(), 0, 2000),
            ]);

            Log::error("❌ ProductImportJob échoué: {$e->getMessage()}");

            $mercureService->post(
                "site/{$this->site->id}/products/indexing",
                [
                    'type' => 'indexing_error',
                    'message' => $e->getMessage(),
                    'done' => true
                ]
            );

            throw $e;
        }
    }
}
