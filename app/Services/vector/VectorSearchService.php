<?php

namespace App\Services\vector;

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
                [
                    'vector' => $embedding,
                    'limit'  => $limit,
                    'with_payload' => true,
                    'score_threshold' => $scoreThreshold,
                    'search_params' => [
                        'hnsw_ef' => 128
                    ],
                    'with_vector' => true, // 🔥 IMPORTANT
                    /*'filter' => [
                        'must' => [
                            [
                                'key' => $collection === 'chunks' ? 'site_id' : 'conversation_id',
                                'match' => [
                                    'value' => $siteId
                                ]
                            ]
                        ]
                    ]*/
                ]
            );

            //dd($response->json(), $siteId, $embedding);
            if ($response->failed()) {
                Log::error('Qdrant search failed', [
                    'collection' => $collection,
                    'status'  => $response->status(),
                    'body'    => $response->body(),
                ]);
                return [];
            }

            //return $response->json('result') ?? [];
            //$result = array_filter($response->json('result') ?? [], fn($item) => $item['payload']['site_id'] === $siteId);
            /*$filterKey = $collection === 'chunks' ? 'site_id' : 'conversation_id';
            $result = array_filter(
                $response->json('result') ?? [],
                fn($item) => isset($item['payload'][$filterKey]) && $item['payload'][$filterKey] === $siteId
            );

            Log::info("resultat de recherche", [
                "site_id" => $siteId,
                "collection" => $collection,
                "results" => $result,
                'response' => $response->json()
            ]);
            return $result;*/
            return $response->json('result') ?? [];

        } catch (\Throwable $e) {
            Log::error('Qdrant search exception', [
                'collection' => $collection,
                'error'   => $e->getMessage(),
            ]);

            return [];
        }
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
