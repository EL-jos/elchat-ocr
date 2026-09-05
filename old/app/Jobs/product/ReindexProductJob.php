<?php

namespace App\Jobs\product;
use romanzipp\QueueMonitor\Traits\IsMonitored;

use App\Models\Document;
use App\Models\Product;
use App\Models\Site;
use App\Services\MercureService;
use App\Services\product\ProductReindexService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReindexProductJob implements ShouldQueue
{
    use IsMonitored;
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 3;

    public function __construct(
        public string $siteId,
        public string $productId,
        public array $productData
    ) {}

    public function handle(ProductReindexService $productReindexService, MercureService $mercureService): void
    {
        Log::info('[JOB REINDEX] Start', [
            'site_id' => $this->siteId,
            'product_id' => $this->productId,
        ]);
        $mercureService->post("site/{$this->siteId}/products/indexing", [
            'type' => 'indexing_progress',
            'progress' => 0,
            'message' => 'Récupération du produit ...',
            'done' => false
        ]);

        $product = Product::findOrFail($this->productId);

        if (!$product) {
            throw new \Exception("Product not found");
        }

        $mercureService->post("site/{$this->siteId}/products/indexing", [
            'type' => 'indexing_progress',
            'progress' => 5,
            'message' => 'Normalisation du produit ...',
            'done' => false
        ]);
        $productUpdating = $this->normalize(product: $product, data: $this->productData);

        $productReindexService->reindexProduct(
            product: $productUpdating,
            productData: $this->productData
        );

        // ✅ Si on arrive ici = succès
        Site::where('id', $this->siteId)
            ->update(['status' => 'ready']);

        Log::info('[JOB REINDEX] Finished successfully', [
            'site_id' => $this->siteId
        ]);
    }

    /**
     * 🔥 Appelé automatiquement si le job échoue après tous les retries
     */
    public function failed(Throwable $exception): void
    {
        Log::error('[JOB REINDEX] Failed', [
            'site_id' => $this->siteId,
            'error' => $exception->getMessage()
        ]);

        Site::where('id', $this->siteId)
            ->update(['status' => 'error']);
    }

    protected function normalize(Product $product, array $data): Product
    {
        $product->update([
            'product_name' => $this->clean($data['product_name'] ?? null),
            'product_reference' => $data['product_reference'] ?? null,
            'product_type' => $data['product_type'] ?? null,
            'product_category' => $data['product_category'] ?? null,
            'description' => $data['description'] ?? null,

            'price' => $this->toFloat($data['price'] ?? null),
            'currency' => strtoupper($data['currency'] ?? 'MAD'),

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
        ]);
        return $product;
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
