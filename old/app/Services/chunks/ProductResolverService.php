<?php

namespace App\Services\chunks;

use App\Models\Chunk;
use App\Models\Site;
use App\Services\ia\EmbeddingService;
use App\Services\vector\VectorSearchService;

class ProductResolverService
{
    public function __construct(
        protected EmbeddingService $embeddingService,
        protected VectorSearchService $vectorSearchService,
        protected ChunkHydrationService $chunkHydrationService
    ) {}

    /**
     * Retrouve les chunks correspondant à la requête de l'utilisateur.
     */
    public function findProductChunks(string $query, Site $site): array
    {
        // 1️⃣ Recherche vectorielle
        $embedding = $this->embeddingService->getEmbedding($query);
        $vectorResults = $this->vectorSearchService->search(
            embedding: $embedding,
            siteId: $site->id,
            limit: 20,
            scoreThreshold: floatval($site->settings->min_similarity_score)
        );

        $chunks = $this->chunkHydrationService->hydrate($vectorResults);

        if (!empty($chunks)) {
            return $chunks;
        }

        // 2️⃣ Fallback recherche SQL sur les métadonnées
        $products = Chunk::where('site_id', $site->id)
            ->whereNotNull('metadata') // sécurité
            ->where(function ($q) use ($query) {
                // Rechercher sur toutes les colonnes internes pertinentes
                $q->where('metadata->product_reference', $query)
                    ->orWhere('metadata->product_name', 'like', "%{$query}%")
                    ->orWhere('metadata->product_type', 'like', "%{$query}%")
                    ->orWhere('metadata->product_category', 'like', "%{$query}%");
            })
            ->get();

        return $products->map(fn($chunk) => [
            'id' => $chunk->id,
            'text' => $chunk->text,
            'vector_score' => 1.0,
            'priority' => $chunk->priority ?? 100,
            'source_type' => $chunk->source_type,
            'metadata' => $chunk->metadata,
            'final_score' => 1.0,
        ])->toArray();
    }
}
