<?php

namespace App\Services\product;

use App\Models\Chunk;
use App\Models\Document;
use App\Models\Product;
use App\Models\Site;
use App\Services\IndexService;
use App\Services\lexical\LexicalIndexService;
use App\Services\MercureService;
use App\Services\vector\VectorIndexService;
use App\Services\vector\VectorSearchService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProductReindexService
{
    public function __construct(
        protected IndexService $indexService,
        protected VectorIndexService $vectorIndexService,
        protected LexicalIndexService  $lexicalIndexService,
        protected MercureService $mercureService,
    ) {}
    /**
     * Liste paginée des produits (chunks globaux uniquement)
     */
    /*public function listProducts(
        string $siteId,
        int $page = 1,
        int $perPage = 20,
        ?string $search = null
    ): LengthAwarePaginator {

        Log::info('[PRODUCT LIST] Début listing produits', [
            'site_id' => $siteId,
            'page' => $page,
            'per_page' => $perPage,
            'search' => $search
        ]);

        $query = Chunk::select(
            'document_id',
            DB::raw("metadata->>'$.product_index' as product_index"),
            DB::raw("MAX(metadata->>'$.identifier') as identifier"),
            DB::raw("MAX(text) as text"),
            DB::raw("MAX(metadata->>'$.raw') as raw")
        )
            ->where('site_id', $siteId)
            ->where('source_type', 'woocommerce')
            ->whereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.type'))) = 'global'")
            ->whereRaw("JSON_EXTRACT(metadata, '$.product_index') IS NOT NULL");

        // 🔎 Recherche simplifiée sur identifier
        if (!empty($search)) {
            $search = strtolower(trim($search));
            $query->whereRaw(
                "LOWER(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.identifier'))) LIKE ?",
                ["%{$search}%"]
            );
        }

        $query->groupBy('document_id', DB::raw("metadata->>'$.product_index'"));

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $paginator->getCollection()->transform(function ($chunk) {
            return [
                'document_id'   => $chunk->document_id,
                'product_index' => (int) $chunk->product_index,
                'identifier'    => $chunk->identifier,
                'global_text'   => $chunk->text,
                'fields'        => json_decode($chunk->raw, true) ?? [],
            ];
        });

        Log::info('[PRODUCT LIST] Fin listing', [
            'total' => $paginator->total()
        ]);

        return $paginator;
    }*/
    public function listProducts(
        string $siteId,
        int $page = 1,
        int $perPage = 20,
        ?string $search = null
    ): LengthAwarePaginator {

        Log::info('[PRODUCT LIST] Start', [
            'site_id' => $siteId,
            'page' => $page,
            'per_page' => $perPage,
            'search' => $search
        ]);

        $query = Product::query()
            ->where('site_id', $siteId);

        // 🔎 Recherche
        if (!empty($search)) {
            $search = strtolower(trim($search));

            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(product_name) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(product_reference) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(description) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(short_description) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(brand) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(tags) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(keywords) LIKE ?', ["%{$search}%"]);
            });
        }

        $paginator = $query
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        // 🔥 Transformation (optionnelle)
        /*$paginator->getCollection()->transform(function ($product) {

            return [
                'product_id' => $product->id,
                'identifier' => $product->product_reference ?? $product->product_name,

                'fields' => [
                    'name' => [$product->product_name],
                    'description' => [$product->description],
                    'short_description' => [$product->short_description],
                    'brand' => [$product->brand],
                    'category' => [$product->product_category],
                    'features' => $this->explodeIfJson($product->features),
                    'colors' => $this->explodeIfJson($product->colors),
                    'materials' => $this->explodeIfJson($product->materials),
                    'tags' => $this->explodeIfJson($product->tags),
                ],

                'content' => array_values(array_filter([
                    $product->product_name,
                    $product->description,
                    $product->short_description,
                    $product->features,
                    $product->brand,
                    $product->product_category
                ]))
            ];
        });*/

        Log::info('[PRODUCT LIST] End', [
            'total' => $paginator->total()
        ]);

        return $paginator;
    }
    /**
     * Réindexe un produit spécifique
     */
    public function reindexProduct(Product $product, array $productData): array
    {
        Log::info('[PRODUCT REINDEX] Démarrage', [
            '$product_id'   => $product->id,
        ]);

        $this->mercureService->post("site/{$product->site->id}/products/indexing", [
            'type' => 'indexing_progress',
            'progress' => 10,
            'message' => 'Démarrage de la ré-indexation ...',
            'done' => false
        ]);

        DB::beginTransaction();

        try {

            /*
            |--------------------------------------------------------------------------
            | 1️⃣ Récupération anciens chunks
            |--------------------------------------------------------------------------
            */
            $this->mercureService->post("site/{$product->site->id}/products/indexing", [
                'type' => 'indexing_progress',
                'progress' => 30,
                'message' => 'Récupération anciens chunks ...',
                'done' => false
            ]);

            $oldChunks = Chunk::where('product_id', $product->id)
                ->where('site_id', $product->site_id)
                ->pluck('id')
                ->all();

            Log::info('[PRODUCT REINDEX] Anciens chunks trouvés', [
                'count' => count($oldChunks)
            ]);
            $nbChunks = count($oldChunks);

            /*
            |--------------------------------------------------------------------------
            | 2️⃣ Suppression Vector DB & Lexical DB
            |--------------------------------------------------------------------------
            */
            $this->mercureService->post("site/{$product->site->id}/products/indexing", [
                'type' => 'indexing_progress',
                'progress' => 50,
                'message' => "Suppression des anciens chunks ({$nbChunks}) dans Vector DB & Lexical DB ...",
                'done' => false
            ]);

            Log::info("ID's anciens chunks", [
                "chunks old" => $oldChunks
            ]);

            $this->vectorIndexService->deleteChunksBatch(chunkIds: $oldChunks, collection: "chunks_{$product->site_id}");
            $this->lexicalIndexService->deleteChunksBatch(chunkIds: $oldChunks, siteId: $product->site_id);

            Log::info('[PRODUCT REINDEX] Suppression Qdrant et Meilisearch OK');

            /*
            |--------------------------------------------------------------------------
            | 3️⃣ Suppression MySQL
            |--------------------------------------------------------------------------
            */
            $this->mercureService->post("site/{$product->site->id}/products/indexing", [
                'type' => 'indexing_progress',
                'progress' => 50,
                'message' => "Suppression des anciens chunks ({$nbChunks}) dans Vector DB & Lexical DB ...",
                'done' => false
            ]);

            $site = Site::find($product->site_id);

            Chunk::where('product_id', $product->id)
                ->where('source_type', 'woocommerce')
                ->where('site_id', $site->id)
                ->delete();

            Log::info('[PRODUCT REINDEX] Suppression MySQL OK');

            /*
            |--------------------------------------------------------------------------
            | 4️⃣ Reconstruction produit
            |--------------------------------------------------------------------------
            */

            $document = new Document([ 'id' => (string) Str::uuid(), 'path' => "unknown", 'type' => "other", 'extension' => "unknown"]);
            $document = $site->documents()->save($document);

            $this->mercureService->post("site/{$product->site->id}/products/indexing", [
                'type' => 'indexing_progress',
                'progress' => 70,
                'message' => "Nouvelle indexation du produit ...",
                'done' => false
            ]);

            $this->indexService->indexStandardProduct(
                product: $product,
                document: $document,
                priority: 50
            );

            Log::info('[PRODUCT REINDEX] Reconstruction terminée');

            /*
            |--------------------------------------------------------------------------
            | 5️⃣ Vérification du chunk global
            |--------------------------------------------------------------------------
            */
            $this->mercureService->post("site/{$product->site->id}/products/indexing", [
                'type' => 'indexing_progress',
                'progress' => 90,
                'message' => "Vérification ...",
                'done' => false
            ]);

            $globalChunk = Chunk::where('product_id', $product->id)
                ->where('source_type', 'woocommerce')
                ->where('metadata->type', 'section')
                ->where('site_id', $product->site_id)
                ->first();

            if (!$globalChunk) {
                throw new \Exception("Global chunk non recréé après réindexation");
            }

            DB::commit();

            Log::info('[PRODUCT REINDEX] Succès', [
                'product_id' => $product->id,
                'product_index' => 50
            ]);
            $this->mercureService->post("site/{$product->site->id}/products/indexing", [
                'type' => 'indexing_progress',
                'progress' => 100,
                'message' => "Réindexation terminée 🎉",
                'done' => true
            ]);

            return [
                'status' => 'success',
                'message' => 'Produit réindexé avec succès',
                'data' => [
                    'document_id'   => $document->id,
                    'product_index' => 50,
                    'product_id'    => $product->id,
                    'global_text'   => $globalChunk->text,
                    'fields'        => $globalChunk->metadata['raw'] ?? [],
                ]
            ];

        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error('[PRODUCT REINDEX] ÉCHEC', [
                'product_id' => $product->id,
                'product_index' => 50,
                'error' => $e->getMessage()
            ]);

            $this->mercureService->post(
                "site/{$product->site->id}/products/indexing",
                [
                    'type' => 'indexing_error',
                    'message' => $e->getMessage(),
                    'done' => true
                ]
            );

            return [
                'status' => 'error',
                'message' => 'Échec de la réindexation',
                'error' => $e->getMessage()
            ];
        }
    }
    /**
     * Réindexe un ou plusieurs produits
     *
     * @param Document $document
     * @param array|int $productIndices  Ex: 3 ou [1,2,3]
     * @param array $productsData        Ex: [1 => [...], 2 => [...]]
     */
    /*public function reindexProducts(Document $document, array|int $productIndices, array $productsData = []): array
    {
        $productIndices = is_array($productIndices) ? $productIndices : [$productIndices];
        $results = [];

        DB::beginTransaction();

        try {
            foreach ($productIndices as $productIndex) {

                Log::info('[PRODUCT REINDEX] Démarrage', [
                    'document_id'   => $document->id,
                    'product_index' => $productIndex
                ]);

                // 1️⃣ Récupération anciens chunks
                $oldChunks = Chunk::where('document_id', $document->id)
                    ->where('source_type', 'woocommerce')
                    ->where('metadata->product_index', $productIndex)
                    ->get();

                Log::info('[PRODUCT REINDEX] Chunks trouvés', [
                    'product_index' => $productIndex,
                    'count' => $oldChunks->count()
                ]);

                // 2️⃣ Suppression Qdrant en batch
                $chunkIds = $oldChunks->pluck('id')->all();
                if (!empty($chunkIds)) {
                    $this->vectorIndexService->deleteChunksBatch($chunkIds, collection: "chunks_{$document->documentable->id}");
                }

                // 3️⃣ Suppression MySQL
                Chunk::whereIn('id', $chunkIds)->delete();
                Log::info('[PRODUCT REINDEX] Suppression MySQL OK', [
                    'deleted_count' => count($chunkIds)
                ]);

                // 4️⃣ Reconstruction produit
                $productData = $productsData[$productIndex] ?? [];
                $this->indexService->indexStandardProduct($productData, $document, $productIndex - 1);

                // 5️⃣ Vérification chunk global
                $globalChunk = Chunk::where('document_id', $document->id)
                    ->where('source_type', 'woocommerce')
                    ->where('metadata->product_index', $productIndex)
                    ->where('metadata->type', 'global')
                    ->first();

                if (!$globalChunk) {
                    throw new \Exception("Global chunk non recréé pour product_index {$productIndex}");
                }

                $results[$productIndex] = [
                    'status' => 'success',
                    'document_id' => $document->id,
                    'product_index' => $productIndex,
                    'identifier' => $globalChunk->metadata['identifier'] ?? null,
                    'global_text' => $globalChunk->text,
                    'fields' => $globalChunk->metadata['raw'] ?? [],
                ];

                Log::info('[PRODUCT REINDEX] Succès', [
                    'document_id' => $document->id,
                    'product_index' => $productIndex
                ]);
            }

            DB::commit();

        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[PRODUCT REINDEX] ÉCHEC', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);

            foreach ($productIndices as $productIndex) {
                $results[$productIndex] = [
                    'status' => 'error',
                    'message' => 'Échec de la réindexation',
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }*/
}
