<?php

namespace App\Services\vector;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class VectorIndexService
{
    protected string $endpoint;
    protected string $collection;

    public function __construct()
    {
        $this->endpoint  = config('qdrant.url'); // http://127.0.0.1:6333
        $this->timeout   = config('qdrant.timeout', 8);
        $this->collection = 'chunks';
    }

    /**
     * Upsert d’un chunk dans Qdrant
     */
    public function upsertChunk(
        string $siteId,
        string $chunkId,
        array $embedding,
        array $payload,
        string $collection = 'chunks'
    ): void {

        $this->collection = $collection;

        try {
            $response = $this->http()->put(
                "{$this->endpoint}/collections/{$this->collection}/points",
                [
                    'points' => [
                        [
                            'id'      => $chunkId,   // UUID MySQL
                            'vector'  => $embedding,
                            'payload' => $payload,
                        ],
                    ],
                ]
            );

            if ($response->failed()) {
                Log::error('Qdrant index failed', [
                    'collection' => $collection,
                    'status'  => $response->status(),
                    'body'    => $response->body(),
                ]);
                return;
            }

            Log::info('Qdrant index success', [
                'collection' => $collection,
                'chunk_id'   => $chunkId,
                'result'     => $response->json(),
            ]);

        } catch (\Throwable $e) {
            // ⚠️ On LOG, mais on ne casse JAMAIS l’indexation
            Log::error('Qdrant upsert failed', [
                'collection' => $collection,
                'chunk_id'   => $chunkId,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Supprimer un chunk
     */
    public function deleteChunk(string $chunkId, string $collection = 'chunks'): void
    {
        $this->collection = $collection;

        try {
            $response = $this->http()->post(
                "{$this->endpoint}/collections/{$this->collection}/points/delete",
                [
                    'points' => [$chunkId],
                ]
            );

            if (!$response->successful()) {
                throw new \Exception('Qdrant upsert failed: '.$response->body());
            }

            Log::info('Qdrant delete success', [
                'collection' => $collection,
                'chunk_id'   => $chunkId,
            ]);

        } catch (\Throwable $e) {

            // ⚠️ On log mais on ne casse PAS la transaction
            Log::error('Qdrant delete failed', [
                'collection' => $collection,
                'chunk_id'   => $chunkId,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Suppression multiple chunks en batch
     */
    public function deleteChunksBatch(array $chunkIds, string $collection = 'chunks'): void
    {
        if (empty($chunkIds)) return;

        $this->collection = $collection;

        try {
            $this->http()->post(
                "{$this->endpoint}/collections/{$this->collection}/points/delete",
                ['points' => $chunkIds]
            );

            Log::info('Qdrant batch delete success', [
                'collection'    => $collection,
                'deleted_count' => count($chunkIds),
            ]);

        } catch (\Throwable $e) {
            Log::error('Qdrant batch delete failed', [
                'collection'      => $collection,
                'chunk_ids_count' => count($chunkIds),
                'error'           => $e->getMessage(),
            ]);
        }
    }

    public function upsertMessage(
        string $conversationId,
        string $messageId,
        array $embedding,
        array $payload
    ): void {

        $collection = "conversations_{$conversationId}";
        $payload['conversation_id'] = $conversationId;

        try {

            $response = $this->http()->put(
                "{$this->endpoint}/collections/{$collection}/points",
                [
                    'points' => [
                        [
                            'id'      => $messageId,
                            'vector'  => $embedding,
                            'payload' => $payload,
                        ],
                    ],
                ]
            );

            if ($response->failed()) {
                Log::error('Qdrant index failed', [
                    'collection' => $collection,
                    'status'  => $response->status(),
                    'body'    => $response->body(),
                ]);
                return;
            }

            Log::info('Qdrant index success', [
                'collection' => $collection,
                'message_id'   => $messageId,
                'result'     => $response->json(),
            ]);

        } catch (Throwable $e) {

            Log::error('Qdrant message upsert failed', [
                'collection' => $collection,
                'message_id' => $messageId,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    public function deleteCollection(string $collection): void
    {
        try {
            $response = $this->http()->delete(
                "{$this->endpoint}/collections/{$collection}"
            );

            if ($response->failed()) {
                Log::error('Qdrant collection delete failed', [
                    'collection' => $collection,
                    'status'     => $response->status(),
                    'body'       => $response->body(),
                ]);
                return;
            }

            Log::info('Qdrant collection deleted', [
                'collection' => $collection,
                'result'     => $response->json(),
            ]);

        } catch (\Throwable $e) {
            Log::error('Qdrant collection delete exception', [
                'collection' => $collection,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    protected function http()
    {
        return Http::timeout(20)
            ->withHeaders([
                'api-key' => config('qdrant.api_key'),
                'Content-Type' => 'application/json',
            ]);
    }
}
