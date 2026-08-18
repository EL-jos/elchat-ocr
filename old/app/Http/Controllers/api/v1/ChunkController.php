<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Jobs\product\ReindexProductJob;
use App\Models\Chunk;
use App\Models\Document;
use App\Models\Product;
use App\Models\Site;
use App\Services\lexical\LexicalIndexService;
use App\Services\product\ProductReindexService;
use App\Services\vector\VectorIndexService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChunkController extends Controller
{
    public function __construct(
        protected ProductReindexService $productReindexService,
        protected VectorIndexService $vectorIndexService,
        protected LexicalIndexService $lexicalIndexService,
    ) {}

    public function indexProducts(Request $request, string $siteId)
    {
        $page = (int)$request->get('page', 1);
        $perPage = (int)$request->get('per_page', 20);
        $search = $request->get('search');

        $paginator = $this->productReindexService->listProducts($siteId, $page, $perPage, $search);

        return response()->json($paginator);
    }

    public function reindexProduct(
        Request $request,
        string $siteId,
        string $productId,
    ) {

        // 1️⃣ Vérifier que le document existe
        $product = Product::findOrFail($productId);

        // 2️⃣ Vérifier que le document appartient bien au site
        $belongsToSite = Chunk::where('product_id', $product->id)
            ->where('site_id', $siteId)
            ->exists();

        if (!$belongsToSite) {
            return response()->json([
                'success' => false,
                'message' => 'Product does not belong to this site.'
            ], 403);
        }

        // 3️⃣ Récupérer les données produit envoyées par Angular
        $productData = $request->input('fields');

        if (empty($productData)) {
            return response()->json([
                'success' => false,
                'message' => 'Product data is required.'
            ], 422);
        }

        // 🔥 DISPATCH JOB
        ReindexProductJob::dispatch(
            $siteId,
            $product->id,
            $productData
        );

        $site = Site::findOrFail($siteId);
        $site->update(['status' => 'indexing']);

        return response()->json([
            'status' => 'queued',
            'message' => 'Reindexation en cours'
        ], 202);
    }

    public function deleteProduct(string $siteId, string $productId)
    {
        Log::info('[PRODUCT DELETE] Démarrage', [
            'product_id' => $productId,
            'site_id'    => $siteId,
        ]);

        DB::beginTransaction();

        try {

            /*
            |--------------------------------------------------------------------------
            | 1️⃣ Vérifier produit
            |--------------------------------------------------------------------------
            */

            $product = Product::findOrFail($productId);

            if ($product->site_id !== $siteId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product does not belong to this site.'
                ], 403);
            }

            /*
            |--------------------------------------------------------------------------
            | 2️⃣ Récupérer les chunks
            |--------------------------------------------------------------------------
            */

            $chunkIds = Chunk::where('product_id', $product->id)
                ->where('site_id', $siteId)
                ->pluck('id')
                ->all();

            Log::info('[PRODUCT DELETE] Chunks trouvés', [
                'count' => count($chunkIds)
            ]);

            /*
            |--------------------------------------------------------------------------
            | 3️⃣ Suppression Vector + Lexical
            |--------------------------------------------------------------------------
            */

            if (!empty($chunkIds)) {
                $this->vectorIndexService->deleteChunksBatch(
                    chunkIds: $chunkIds,
                    collection: "chunks_{$siteId}"
                );

                $this->lexicalIndexService->deleteChunksBatch(
                    chunkIds: $chunkIds,
                    siteId: $siteId
                );
            }

            Log::info('[PRODUCT DELETE] Suppression vector & lexical OK');

            /*
            |--------------------------------------------------------------------------
            | 4️⃣ Suppression MySQL (chunks)
            |--------------------------------------------------------------------------
            */

            Chunk::where('product_id', $product->id)
                ->where('site_id', $siteId)
                ->delete();

            Log::info('[PRODUCT DELETE] Suppression MySQL chunks OK');

            /*
            |--------------------------------------------------------------------------
            | 5️⃣ (Optionnel) Supprimer documents liés
            |--------------------------------------------------------------------------
            */

            /*Document::whereHas('chunks', function ($q) use ($productId) {
                $q->where('product_id', $productId);
            })->delete();

            Log::info('[PRODUCT DELETE] Documents liés supprimés');*/

            /*
            |--------------------------------------------------------------------------
            | 6️⃣ (Optionnel) Supprimer produit
            |--------------------------------------------------------------------------
            */

            $product->delete();

            Log::info('[PRODUCT DELETE] Produit supprimé');

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Produit supprimé avec succès'
            ]);

        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error('[PRODUCT DELETE] ÉCHEC', [
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function deleteProducts(Request $request, string $siteId)
    {
        $productIds = $request->input('ids', []);

        Log::info('[PRODUCT BATCH DELETE] Démarrage', [
            'site_id' => $siteId,
            'count'   => count($productIds),
        ]);

        DB::beginTransaction();

        try {

            /*
            |--------------------------------------------------------------------------
            | 1️⃣ Vérifier produits
            |--------------------------------------------------------------------------
            */

            $products = Product::whereIn('id', $productIds)
                ->where('site_id', $siteId)
                ->get();

            if ($products->count() !== count($productIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Certains produits sont invalides ou n\'appartiennent pas au site.'
                ], 403);
            }

            /*
            |--------------------------------------------------------------------------
            | 2️⃣ Récupérer tous les chunks
            |--------------------------------------------------------------------------
            */

            $chunkIds = Chunk::whereIn('product_id', $productIds)
                ->where('site_id', $siteId)
                ->pluck('id')
                ->all();

            Log::info('[PRODUCT BATCH DELETE] Chunks trouvés', [
                'count' => count($chunkIds)
            ]);

            /*
            |--------------------------------------------------------------------------
            | 3️⃣ Suppression Vector + Lexical
            |--------------------------------------------------------------------------
            */

            if (!empty($chunkIds)) {
                $this->vectorIndexService->deleteChunksBatch(
                    chunkIds: $chunkIds,
                    collection: "chunks_{$siteId}"
                );

                $this->lexicalIndexService->deleteChunksBatch(
                    chunkIds: $chunkIds,
                    siteId: $siteId
                );
            }

            /*
            |--------------------------------------------------------------------------
            | 4️⃣ Suppression MySQL (chunks)
            |--------------------------------------------------------------------------
            */

            Chunk::whereIn('product_id', $productIds)
                ->where('site_id', $siteId)
                ->delete();

            Log::info('[PRODUCT BATCH DELETE] Chunks supprimés');

            /*
            |--------------------------------------------------------------------------
            | 5️⃣ Suppression produits
            |--------------------------------------------------------------------------
            */

            Product::whereIn('id', $productIds)
                ->where('site_id', $siteId)
                ->delete();

            Log::info('[PRODUCT BATCH DELETE] Produits supprimés');

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Produits supprimés avec succès'
            ]);

        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error('[PRODUCT BATCH DELETE] ÉCHEC', [
                'site_id' => $siteId,
                'error'   => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}
