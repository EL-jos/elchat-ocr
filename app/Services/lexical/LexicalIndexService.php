<?php

namespace App\Services\lexical;

use Exception;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Meilisearch\Client;
use Throwable;

class LexicalIndexService
{
    protected Client $client;

    public function __construct()
    {
        $this->client = new Client(
            config('meilisearch.url'),
            config('meilisearch.key')
        );
    }

    public function createIndexIfNotExists(string $siteId): bool{

        $indexName = "chunks_{$siteId}";

        $this->log('info', 'Checking index existence', [
            'site_id' => $siteId,
            'index' => $indexName,
        ]);

        try {

            // Vérifie si l’index existe déjà
            $this->client->getIndex($indexName);
            $this->log('info', 'Index already exists', [
                'site_id' => $siteId,
                'index' => $indexName,
            ]);
            return false; // existe déjà → rien à faire

        } catch (Throwable $e) {
            $this->log('warning', 'Index not found, creating', [
                'site_id' => $siteId,
                'index' => $indexName,
                'error' => $e->getMessage(),
            ]);

            // index n'existe pas → on le crée
            $this->client->createIndex($indexName, [
                'primaryKey' => 'id',
            ]);
        }

        $this->applySettings($siteId);
        $this->log('info', 'Index created successfully', [
            'site_id' => $siteId,
            'index' => $indexName,
        ]);
        return true;
    }
    protected function applySettings(string $siteId): void{

        $this->log('info', 'Applying index settings', [
            'site_id' => $siteId,
        ]);

        $this->index($siteId)->updateSettings([
            'searchableAttributes' => [
                'title',
                'content',
                'text',
                'aliases', // 🔥 MAIS en dernier
            ],
            'filterableAttributes' => [
                'site_id',
                'source_type',
            ],
            'sortableAttributes' => [
                'priority',
            ],
            'rankingRules' => [
                'words',
                'typo',
                'proximity',
                'attribute',
                'sort',
                'exactness',
            ],
            'typoTolerance' => [
                'enabled' => true,
            ]
            /*'attributesToSearchOn' => [
                'title',
                'text',
                'content'
            ],*/
        ]);

        $this->log('info', 'Settings applied', [
            'site_id' => $siteId,
        ]);

    }
    protected function index(string $siteId)
    {
        return $this->client->index("chunks_$siteId");
    }

    /**
     * 🔥 Initialise l'index (CRITIQUE)
     */
    public function ensureIndex(string $siteId): void
    {

        if (cache()->get("meili_index_ready_{$siteId}")) {
            $this->log('debug', 'Index already cached as ready', [
                'site_id' => $siteId,
            ]);
            return;
        }

        $this->log('info', 'Ensuring index readiness', [
            'site_id' => $siteId,
        ]);

        $this->createIndexIfNotExists($siteId);

        cache()->put("meili_index_ready_{$siteId}", true, 86400);

        $this->log('info', 'Index marked as ready in cache', [
            'site_id' => $siteId,
        ]);
    }

    /**
     * Upsert chunk
     */
    public function upsertChunk(array $doc, string $siteId): void
    {
        $this->log('info', 'Upserting chunk', [
            'site_id' => $siteId,
            'chunk_id' => $doc['id'] ?? null,
        ]);
        $this->log('debug', 'Chunk payload', [
            'site_id' => $siteId,
            'doc' => $doc,
        ]);

        $this->index($siteId)->addDocuments([$doc], 'id'); // 🔥 PRIMARY KEY

        $this->log('info', 'Chunk upserted successfully', [
            'site_id' => $siteId,
            'chunk_id' => $doc['id'] ?? null,
        ]);
    }
    public function deleteChunk(string $chunkId, string $siteId): void
    {
        $this->log('warning', 'Deleting single chunk', [
            'site_id' => $siteId,
            'chunk_id' => $chunkId,
        ]);

        try {

            $this->index($siteId)->deleteDocument($chunkId);

            $this->log('info', 'Chunk deleted', [
                'site_id' => $siteId,
                'chunk_id' => $chunkId,
            ]);

        } catch (Throwable $e) {

            $this->log('error', 'Chunk deletion failed', [
                'site_id' => $siteId,
                'chunk_id' => $chunkId,
                'error' => $e->getMessage(),
            ]);
        }
    }
    public function deleteChunksBatch(array $chunkIds, string $siteId): void
    {
        if (empty($chunkIds)) {

            $this->log('debug', 'Batch delete skipped (empty array)', [
                'site_id' => $siteId,
            ]);

            return;
        }

        $this->log('warning', 'Batch delete started', [
            'site_id' => $siteId,
            'count' => count($chunkIds),
            'chunk_ids_sample' => array_slice($chunkIds, 0, 5),
        ]);

        try {
            $task = $this->index($siteId)->deleteDocuments($chunkIds);

            Log::info('Meilisearch batch delete triggered', [
                'site_id' => $siteId,
                'deleted_count' => count($chunkIds),
                'task_uid' => $task['taskUid'] ?? null,
            ]);

        } catch (Throwable $e) {
            Log::error('Meilisearch batch delete failed', [
                'site_id' => $siteId,
                'deleted_count' => count($chunkIds),
                'error' => $e->getMessage(),
            ]);
        }
    }
    public function deleteIndex(string $siteId)
    {
        try {
            $task = $this->index($siteId)->delete();
            Log::info('Meilisearch batch delete triggered', [
                'site_id' => $siteId,
                'task' => json_encode($task),
                'task_uid' => $task['taskUid'] ?? null,
            ]);

        } catch (Throwable $e) {
            Log::error('Meilisearch batch delete failed', [
                'site_id' => $siteId,
                'task' => json_encode($task),
                'error' => $e->getMessage(),
            ]);
        }
    }
    public function search(string $query, string $siteId, int $limit = 20): array
    {
        /*$this->log('info', 'Search executed', [
            'site_id' => $siteId,
            'query' => $query,
            'limit' => $limit,
        ]);*/

        $results = $this->index($siteId)->search($query, [
            'limit' => $limit,
            //'sort' => ['priority:desc'], // ✅ ICI
            'showRankingScore' => true, // 🔥 OBLIGATOIRE
        ]);

        //$hits = $results['hits'] ?? [];
        $hits = $results->getHits() ?? [];

        /*$this->log('debug', 'Search results', [
            'site_id' => $siteId,
            'hits_count' => count($hits),
        ]);*/

        return collect($hits)
            ->map(function ($hit) {
                /*$this->log('debug', 'HIT SCORE', [
                    '_rankingScore' => $hit['_rankingScore'],
                ]);*/
                return [
                    'id' => $hit['id'],
                    'score' => $hit['_rankingScore'] ?? 0,
                    'source' => 'keyword',
                    'payload' => $hit,
                ];
            })
            ->toArray();
    }

    /**
     * URL de recherche REST utilisée uniquement par la recherche hybride
     * parallèle. Le SDK reste utilisé par search() et les opérations d'index.
     */
    public function buildSearchUrl(string $siteId): string
    {
        return rtrim((string) config('meilisearch.url'), '/') . "/indexes/chunks_{$siteId}/search";
    }

    public function buildSearchPayload(string $query, int $limit): array
    {
        return [
            'q' => $query,
            'limit' => $limit,
            'showRankingScore' => true,
        ];
    }

    /**
     * Parse une réponse Meilisearch ou une exception issue d'une requête
     * poolée, en conservant le format de sortie de search().
     */
    public function parseSearchResponse(mixed $response): array
    {
        if (
            $response instanceof Throwable
            || ! ($response instanceof Response)
            || $response->failed()
        ) {
            Log::error('Meilisearch raw search failed', [
                'status' => $response instanceof Response ? $response->status() : null,
                'error' => $response instanceof Throwable
                    ? $response->getMessage()
                    : ($response instanceof Response ? $response->body() : 'Invalid response'),
            ]);

            return [];
        }

        $hits = $response->json('hits') ?? [];

        if (! is_array($hits)) {
            return [];
        }

        return collect($hits)
            ->map(fn (array $hit) => [
                'id' => $hit['id'],
                'score' => $hit['_rankingScore'] ?? 0,
                'source' => 'keyword',
                'payload' => $hit,
            ])
            ->toArray();
    }

    private function log(string $level, string $message, array $context = []): void
    {
        Log::$level($message, array_merge([
            'service' => 'LexicalIndexService',
            'timestamp' => now()->toISOString(),
        ], $context));
    }


}
