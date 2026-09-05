<?php

namespace App\Services\vector;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VectorSearchService
{
    protected string $baseUrl;
    protected string $collection;
    protected int $timeout;

    public function __construct()
    {
        $this->baseUrl   = config('qdrant.url');
        $this->collection = config('qdrant.collection');
        $this->timeout    = config('qdrant.timeout', 8);
    }

    /**
     * Recherche vectorielle principale
     *
     * @return array [
     *   [
     *     'id' => 'uuid',
     *     'score' => float,
     *     'payload' => [...]
     *   ]
     * ]
     */
    public function search(
        array $embedding,
        string $siteId,
        int $limit = 12,
        float $scoreThreshold = 0.25,
        string $collection = 'chunks'
    ): array {
        $this->collection = $collection;
       /* Log::info("QDRANT SEARCH", [
            'collection' => $this->collection,
            'baseUrl'    => $this->baseUrl,
            'scoreThreshold' => $scoreThreshold,
            'limit'       => $limit,
            'siteId'      => $siteId,
        ]);*/
        try {
            $response = $this->http()->post(
                "{$this->baseUrl}/collections/{$this->collection}/points/search",
                $this->buildSearchPayload($embedding, $limit, $scoreThreshold)
            );

            return $this->parseSearchResponse($response, $collection);

        } catch (\Throwable $e) {
            Log::error('Qdrant search exception', [
                'collection' => $collection,
                'error'   => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Payload commun aux recherches Qdrant séquentielles et parallèles.
     *
     * Le filtre site_id reste volontairement absent, comme dans le payload
     * historique de search().
     */
    public function buildSearchPayload(array $embedding, int $limit, float $scoreThreshold): array
    {
        return [
            'vector' => $embedding,
            'limit' => $limit,
            'with_payload' => true,
            'score_threshold' => $scoreThreshold,
            'search_params' => [
                'hnsw_ef' => 128,
            ],
            'with_vector' => true,
        ];
    }

    /**
     * Parse une réponse Qdrant ou une exception issue d'une requête poolée.
     */
    public function parseSearchResponse(mixed $response, ?string $collection = null): array
    {
        if (
            $response instanceof \Throwable
            || ! ($response instanceof Response)
            || $response->failed()
        ) {
            Log::error('Qdrant search failed', [
                'collection' => $collection,
                'status' => $response instanceof Response ? $response->status() : null,
                'error' => $response instanceof \Throwable
                    ? $response->getMessage()
                    : ($response instanceof Response ? $response->body() : 'Invalid response'),
            ]);

            return [];
        }

        $results = $response->json('result') ?? [];

        return is_array($results) ? $results : [];
    }

    public function searchMessages(
        array $embedding,
        string $conversationId,
        int $limit = 10,
        float $scoreThreshold = 0.25,
        string $collection = 'messages'
    ): array {

        $this->collection = $collection;
        try {

            $response = $this->http()->post(
                "{$this->baseUrl}/collections/{$collection}/points/search",
                [
                    'vector' => $embedding,
                    'limit'  => $limit,
                    'with_payload' => true,
                    'score_threshold' => $scoreThreshold,
                    'filter' => [
                        'must' => [
                            [
                                'key' => 'conversation_id',
                                'match' => [
                                    'value' => $conversationId
                                ]
                            ]
                        ]
                    ]
                ]
            );

            if ($response->failed()) {
                Log::error('Qdrant message search failed', [
                    'collection' => $collection,
                    'status'     => $response->status(),
                    'body'       => $response->body(),
                ]);
                return [];
            }

            return $response->json('result') ?? [];

        } catch (\Throwable $e) {

            Log::error('Qdrant message search exception', [
                'collection' => $collection,
                'error'      => $e->getMessage(),
            ]);

            return [];
        }
    }

    protected function http()
    {
        return Http::timeout(10)
            ->withHeaders([
                'api-key' => config('qdrant.api_key'),
                'Content-Type' => 'application/json',
            ]);
    }
}
