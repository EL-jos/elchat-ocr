<?php

namespace App\Services\hybrid;

use App\Services\lexical\LexicalIndexService;
use App\Services\vector\VectorSearchService;
use Illuminate\Support\Facades\Log;

class HybridSearchService
{
    protected int $rrfK = 60;

    public function __construct(
        protected VectorSearchService $vectorSearch,
        protected LexicalIndexService $lexicalSearch
    ) {}

    public function search(
        string $query,
        array $embedding,
        string $siteId,
        int $limit = 10,
        float $scoreThreshold = 0.15,
    ): array {

        $collection = "chunks_{$siteId}";

        // 1️⃣ Retrieve
        $vectorResults = $this->vectorSearch->search(
            embedding: $embedding,
            siteId: $siteId,
            limit: $limit,
            scoreThreshold: $scoreThreshold,
            collection: $collection,
        );

        $vectorResults = collect($vectorResults)
            ->unique('id')
            ->values()
            ->toArray();

        $keywordResults = $this->lexicalSearch->search(
            query: $query,
            siteId: $siteId,
            limit: $limit
        );

        /*Log::info("RESULTAT LEXICAL", [
            'keywords' => $keywordResults,
        ]);*/

        $keywordResults = collect($keywordResults)
            ->unique('id')
            ->values()
            ->toArray();

        // 2️⃣ Convert to ranked lists
        // 🔥 Dynamic weighting (très important)
        [$vectorWeight, $keywordWeight] = $this->getWeights($query);

        $rankedLists = [
            [
                'type' => 'vector',
                'list' => $this->toRankedList($vectorResults),
                'weight' => $vectorWeight,
                'raw' => $vectorResults, // 🔥 IMPORTANT
            ],
            [
                'type' => 'keyword',
                'list' => $this->toRankedList($keywordResults),
                'weight' => $keywordWeight,
                'raw' => $keywordResults, // 🔥 IMPORTANT
            ],
        ];

        // 3️⃣ RRF Fusion
        //$fused = $this->reciprocalRankFusion($rankedLists);
        /*Log::info("SOURCES DE LA RECHERCHE", [
            "rankedLists" => $rankedLists,
        ]);*/
        $fused = $this->reciprocalRankFusionWeighted($rankedLists);

        // 4️⃣ Hydratation minimale (ids uniquement)
        return collect($fused)
            //->take($limit)
            ->values()
            ->toArray();
    }
    /**
     * Transforme résultats en ranking pur
     */
    protected function toRankedList(array $results): array
    {
        return collect($results)
            ->values()
            ->map(fn($r, $index) => [
                'id' => $r['id'],
                'rank' => $index + 1,
            ])
            ->toArray();
    }
    /**
     * 🔥 RRF core
     */
    protected function reciprocalRankFusion(array $rankedLists): array
    {
        $scores = [];

        foreach ($rankedLists as $list) {
            foreach ($list as $item) {

                $id = $item['id'];
                $rank = $item['rank'];

                if (!isset($scores[$id])) {
                    $scores[$id] = 0;
                }

                $scores[$id] += 1 / ($this->rrfK + $rank);
            }
        }

        return collect($scores)
            ->map(fn($score, $id) => [
                'id' => $id,
                'score' => $score
            ])
            ->sortByDesc('score')
            ->values()
            ->toArray();
    }
    protected function reciprocalRankFusionWeighted(array $sources): array
    {
        $scores = [];

        // 🔥 maps pour récupérer metadata
        $vectorMap = collect();
        $keywordMap = collect();

        foreach ($sources as $source) {
            if ($source['type'] === 'vector') {
                $vectorMap = collect($source['raw'])->keyBy('id');
            } else {
                $keywordMap = collect($source['raw'])->keyBy('id');
            }
        }

        foreach ($sources as $source) {
            $weight = $source['weight'];
            $list = $source['list'];

            foreach ($list as $item) {

                $id = $item['id'];
                $rank = $item['rank'];

                if (!isset($scores[$id])) {
                    $scores[$id] = 0;
                }

                $rrf = $weight * (1 / ($this->rrfK + $rank));

                $vectorScore = $vectorMap[$id]['score'] ?? 0;
                $keywordScore = $keywordMap[$id]['score'] ?? 0;

                // 🔥 NORMALISATION SIMPLE
                $vectorNorm = $vectorScore; // déjà 0-1
                $maxKeyword = $keywordMap->max('score') ?: 1;
                $keywordNorm = $keywordScore / $maxKeyword;

                // 🔥 HYBRID BOOST
                $scoreContribution =
                    $rrf +
                    (0.15 * $vectorNorm) +
                    (0.15 * $keywordNorm);

                $scores[$id] += $scoreContribution;
            }
        }

        /*Log::info("Dans reciprocalRankFusionWeighted", [
            "sources" => $sources,
            "scores" => $scores,
            "vectorMap" => $vectorMap,
            "keywordMap" => $keywordMap,
        ]);*/

        // 🔥 ENRICHISSEMENT FINAL
        return collect($scores)
            ->map(function ($score, $id) use ($vectorMap, $keywordMap) {

                if ($vectorMap->has($id) && $keywordMap->has($id)) {
                    $score += 0.1;
                    //$score *= 1.15;
                }

                return [
                    'id' => $id,
                    // 🔥 score initial = RRF enrichi
                    'score' => $score,
                    // 🔥 trace immutable
                    'rrf_score' => $score,
                    // 🔥 CRITIQUE pour ton pipeline
                    'vector_score' => $vectorMap[$id]['score'] ?? null,
                    'keyword_score' => $keywordMap[$id]['score'] ?? null,
                    // 🔥 nécessaire pour hydration / ranking
                    'payload' => $vectorMap[$id]['payload']
                        ?? $keywordMap[$id]['payload']
                            ?? ['id' => $id],
                    'source' => $vectorMap->has($id) && $keywordMap->has($id)
                        ? 'hybrid'
                        : ($vectorMap->has($id) ? 'vector' : 'keyword'),
                    'embedding' => $vectorMap[$id]['vector'] ?? null,
                ];
            })
            //->filter(fn($item) => $item['score'] > 0.01)
            ->sortByDesc('score')
            ->values()
            ->toArray();
    }
    protected function getWeights(string $query): array
    {
        // requête exacte (produit, code, référence)
        if ($this->isExactQuery($query)) {
            return [0.4, 0.6]; // keyword dominant
        }

        // requête naturelle
        return [0.7, 0.3]; // vector dominant
    }
    protected function isExactQuery(string $query): bool
    {
        return preg_match('/\b[A-Z0-9\-]{4,}\b/', $query)
            && strlen($query) < 40;
    }
}
