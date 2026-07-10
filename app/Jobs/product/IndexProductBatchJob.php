<?php

namespace App\Jobs\product;

use App\Models\Document;
use App\Models\Product;
use App\Models\ProductImport;
use App\Services\IndexService;
use App\Services\MercureService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class IndexProductBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 18000;

    public function __construct(
        public array $products,
        public Document $document,
        public string $importId,
        public string $siteId
    ) {}

    public function handle(IndexService $indexService, MercureService $mercureService)
    {
        Log::info("📦 Batch import produits démarré ({$this->importId})");

        $siteId = $this->siteId;
        $import = ProductImport::find($this->importId);

        if (!$import) {
            Log::error("❌ Import introuvable ({$this->importId})");
            return;
        }

        foreach ($this->products as $index => $data) {

            try {
                // 🔹 1. Normalisation
                $productData = $this->normalize(siteId: $siteId, data: $data);

                if (empty($productData['product_name'])) {
                    continue;
                }

                // 🔹 2. Clé unique (idempotence)
                $uniqueKey = $productData['product_reference']
                    ? ['product_reference' => $productData['product_reference']]
                    : ['product_name' => $productData['product_name']];

                Log::info("Produit AVANT CREATION", [
                    'Unique' => $uniqueKey,
                    'Produit Data' => $productData,
                    'Site ID' => $siteId,
                ]);

                // 🔹 3. Persist (source de vérité)
                $product = Product::updateOrCreate(
                    $uniqueKey,
                    $productData
                );

                Log::info("Produit CREE", [
                    'Produit ID' => $product->id,
                    'Produit reference' => $product->product_reference,
                    'Produit nom' => $product->product_name,
                    'Site ID' => $siteId,
                ]);

                /*
                |--------------------------------------------------------------------------
                | 🔥 👉 ICI TU APPELLES TON INDEX SERVICE
                |--------------------------------------------------------------------------
                |
                | Exemple :
                |
                | $indexService->indexStandardProduct($product, $this->document, $index);
                |
                */

                //if ($product->wasRecentlyCreated || $product->wasChanged()) {
                    $indexService->indexStandardProduct($product, $this->document, $index);
                //}
                // 🔹 4. Progress tracking (ATOMIQUE)
                ProductImport::where('id', $this->importId)
                    ->increment('processed_products');

                // 🔹 5. Progress realtime
                $processed = ProductImport::where('id', $this->importId)
                    ->value('processed_products');

                /*$progress = $import->total_products > 0
                    ? round(($processed / $import->total_products) * 100)
                    : 0;*/

                $progress = 10 + round(($processed / $import->total_products) * 90);

                $mercureService->post(
                    "site/{$this->siteId}/products/indexing",
                    [
                        'type' => 'indexing_progress',
                        'progress' => $progress,
                        'processed' => $processed,
                        'total' => $import->total_products,
                        'message' => "Traitement des produits ({$processed}/{$import->total_products})",
                        'done' => false
                    ]
                );

            } catch (\Throwable $e) {
                Log::error("❌ Erreur produit", [
                    'error' => $e->getMessage(),
                    'data' => $data
                ]);
                $mercureService->post(
                    "site/{$this->siteId}/products/indexing",
                    [
                        'type' => 'indexing_warning', // 🔥 change
                        'message' => "Produit ignoré: " . $e->getMessage(),
                        'done' => false
                    ]
                );
            }

        }

        /*// 🔹 4. Progress tracking
        ProductImport::where('id', $this->importId)
            ->increment('processed_products', count($this->products));*/

        Log::info("✅ Batch terminé ({$this->importId})");

    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    protected function normalize(string $siteId, array $data): array
    {
        return [
            'site_id' => $siteId,
            'product_name' => $this->clean($data['product_name'] ?? null),
            'product_reference' => $data['product_reference'] ?? null,
            'product_type' => $data['product_type'] ?? null,
            'product_category' => $data['product_category'] ?? null,
            'description' => $data['description'] ?? null,

            'price' => $this->toFloat($data['price'] ?? null),
            'currency' => strtoupper($data['currency'] ?? null),

            'price_min' => $this->toFloat($data['price_min'] ?? null),
            'price_max' => $this->toFloat($data['price_max'] ?? null),
            'discount_price' => $this->toFloat($data['discount_price'] ?? null),
            'tax_rate' => $this->toFloat($data['tax_rate'] ?? null),

            'short_description' => $data['short_description'] ?? null,
            'features' => $data['features'] ?? null,
            'brand' => $data['brand'] ?? null,
            'tags' => $this->toJson($data['tags'] ?? null),
            'keywords' => $this->toJson($data['keywords'] ?? null),

            'stock_status' => $data['stock_status'] ?? null,
            'stock_quantity' => (int) ($data['stock_quantity'] ?? 0),

            'weight' => $data['weight'] ?? null,
            'dimensions' => $data['dimensions'] ?? null,
            'colors' => $data['colors'] ?? null,
            'materials' => $data['materials'] ?? null,
            'availability' => $data['availability'] ?? null,

            'image_url' => $data['image_url'] ?? null,
            'product_url' => $data['product_url'] ?? null,
            'gallery_urls' => $this->toJson($data['gallery_urls'] ?? null),
            'video_url' => $data['video_url'] ?? null,

            'status' => $data['status'] ?? 'active',
            'language' => $data['language'] ?? 'fr',
            'visibility' => $data['visibility'] ?? 'public',
            'created_in_website_at' => $data['created_at'] ?? null,
        ];
    }

    protected function clean($value)
    {
        return $value ? trim($value) : null;
    }
    protected function toFloat($value)
    {
        if (!$value) return null;
        return (float) str_replace(',', '.', $value);
    }
    protected function toJson($value)
    {
        if (!$value) return null;

        if (is_array($value)) {
            return json_encode($value);
        }

        return json_encode(array_map('trim', explode(',', $value)));
    }
}
