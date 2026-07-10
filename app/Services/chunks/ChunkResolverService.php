<?php

namespace App\Services\chunks;

use App\Models\Chunk;
use App\Models\Site;
use App\Models\UnansweredQuestion;
use App\Services\ia\EmbeddingService;
use App\Services\vector\VectorSearchService;

class ChunkResolverService
{
    public function __construct(
        protected EmbeddingService $embeddingService,
        protected VectorSearchService $vectorSearchService,
        protected ChunkHydrationService $chunkHydrationService
    ) {}
    public function resolveChunks(string $query, Site $site): array
    {
        // 1️⃣ Embedding de la question
        $questionEmbedding = $this->embeddingService->getEmbedding($query);

        // 2️⃣ Recherche vectorielle Qdrant
        $qdrantResults = $this->vectorSearchService->search(
            embedding: $questionEmbedding,
            siteId: $site->id,
            limit: 20,
            scoreThreshold: floatval($site->settings->min_similarity_score)
        );

        // 3️⃣ Fallback si rien trouvé
        if (empty($qdrantResults)) {
            UnansweredQuestion::create([
                'site_id' => $site->id,
                'question' => $query,
            ]);

            //dd(empty($qdrantResults), $qdrantResults, $site->id, floatval($site->settings->min_similarity_score));
            return []/*"Je n’ai pas trouvé cette information dans les données de notre entreprise.
            N’hésitez pas à nous préciser votre besoin ou à nous contacter directement."*/;
        }

        // 4️⃣ Hydratation MySQL
        $hydrated = $this->chunkHydrationService->hydrate($qdrantResults);

        return $hydrated;
    }
}
