<?php

namespace App\Services\hybrid;

use App\Services\lexical\LexicalIndexService;
use App\Services\vector\VectorSearchService;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;

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
        return $this->searchMany([[
            'query' => $query,
            'embedding' => $embedding,
            'siteId' => $siteId,
            'limit' => $limit,
            'scoreThreshold' => $scoreThreshold,
        ]])[0] ?? [];
    }

    /**
     * Exécute plusieurs recherches hybrides dans un seul pool HTTP.
     *
     * Le tableau retourné conserve exactement l'ordre des requêtes entrantes.
     * La méthode ne fusionne jamais les requêtes entre elles : chaque entrée
     * reçoit son propre RRF, ce qui préserve le scoring historique de search().
     * Le caller peut découper les entrées en lots pour contrôler la concurrence.
     *
     * @param array<int, array{
     *     query:string,
     *     embedding:array<int, float>,
     *     siteId:string,
     *     limit?:int,
     *     scoreThreshold?:float
     * }> $requests
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function searchMany(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        $prepared = [];

        foreach (array_values($requests) as $request) {
            if (! is_array($request)) {
                throw new \InvalidArgumentException('Hybrid search requests must be arrays.');
            }

            $query = $request['query'] ?? null;
            $embedding = $request['embedding'] ?? null;
            $siteId = $request['siteId'] ?? null;

            if (
                ! is_string($query)
                || trim($query) === ''
                || ! is_array($embedding)
                || $embedding === []
                || ! is_string($siteId)
                || trim($siteId) === ''
            ) {
                throw new \InvalidArgumentException('Invalid hybrid search request.');
            }

            $prepared[] = [
                'query' => $query,
                'embedding' => $embedding,
                'siteId' => $siteId,
                'limit' => max(1, (int) ($request['limit'] ?? 10)),
                'scoreThreshold' => (float) ($request['scoreThreshold'] ?? 0.15),
            ];
        }

        $responses = Http::pool(function (Pool $pool) use ($prepared): array {
            $poolRequests = [];

            foreach ($prepared as $index => $request) {
                $collection = "chunks_{$request['siteId']}";
                $qdrantUrl = rtrim((string) config('qdrant.url'), '/')
                    . "/collections/{$collection}/points/search";
                $vectorAlias = "vector_{$index}";
                $keywordAlias = "keyword_{$index}";

                $poolRequests[$vectorAlias] = $pool->as($vectorAlias)
                    ->timeout((int) config('qdrant.timeout', 8))
                    ->withHeaders([
                        'api-key' => config('qdrant.api_key'),
                        'Content-Type' => 'application/json',
                    ])
                    ->post(
                        $qdrantUrl,
                        $this->vectorSearch->buildSearchPayload(
                            $request['embedding'],
                            $request['limit'],
                            $request['scoreThreshold'],
                        ),
                    );

                $poolRequests[$keywordAlias] = $pool->as($keywordAlias)
                    ->timeout(8)
                    ->withHeaders([
                        'Authorization' => 'Bearer ' . config('meilisearch.key'),
                        'Content-Type' => 'application/json',
                    ])
                    ->post(
                        $this->lexicalSearch->buildSearchUrl($request['siteId']),
                        $this->lexicalSearch->buildSearchPayload(
                            $request['query'],
                            $request['limit'],
                        ),
                    );
            }

            return $poolRequests;
        });

        $results = [];

        foreach ($prepared as $index => $request) {
            $collection = "chunks_{$request['siteId']}";
            $vectorResults = collect($this->vectorSearch->parseSearchResponse(
                $responses["vector_{$index}"] ?? null,
                $collection,
            ))
                ->unique('id')
                ->values()
                ->toArray();

            $keywordResults = collect($this->lexicalSearch->parseSearchResponse(
                $responses["keyword_{$index}"] ?? null,
            ))
                ->unique('id')
                ->values()
                ->toArray();

            $results[] = $this->fuseSearchResults(
                $request['query'],
                $vectorResults,
                $keywordResults,
            );
        }

        return $results;
    }

    /**
     * Applique la fusion historique à une paire de résultats.
     */
    protected function fuseSearchResults(string $query, array $vectorResults, array $keywordResults): array
    {
        // Convertir en ranked lists sans modifier la formule RRF existante.
        [$vectorWeight, $keywordWeight] = $this->getWeights($query);

        $rankedLists = [
            [
                'type' => 'vector',
                'list' => $this->toRankedList($vectorResults),
                'weight' => $vectorWeight,
                'raw' => $vectorResults,
            ],
            [
                'type' => 'keyword',
                'list' => $this->toRankedList($keywordResults),
                'weight' => $keywordWeight,
                'raw' => $keywordResults,
            ],
        ];

        return collect($this->reciprocalRankFusionWeighted($rankedLists))
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
