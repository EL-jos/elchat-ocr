<?php

namespace App\Services\vector;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VectorCreationService
{
    protected string $baseUrl;
    protected int $timeout;

    public function __construct()
    {
        $this->baseUrl = config('qdrant.url');
        $this->timeout = config('qdrant.timeout', 8);
    }

    /**
     * Crée une collection dédiée pour un site donné
     *
     * @param string $siteId UUID du site
     * @param int $vectorSize Taille du vecteur (ex: 1536)
     * @param string $distance Distance metric (Cosine, Euclid, Dot)
     * @return bool
     */
    public function createSiteCollection(string $siteId, int $vectorSize = 1536, string $distance = 'Cosine', string $collection = "chunks"): bool
    {
        $collectionName = $collection;

        try {
            // 1️⃣ Vérifier si la collection existe
            $response = $this->http()->get("{$this->baseUrl}/collections/{$collectionName}");
            if ($response->ok()) {
                Log::info("Collection {$collectionName} existe déjà");
                return true;
            }
        } catch (\Throwable $e) {
            // Si erreur 404, on crée la collection
        }

        // Création de la collection
        $payload = [
            'vectors' => [
                'size' => $vectorSize,
                'distance' => $distance,
            ],
            'on_disk_payload' => true,
            'replication_factor' => 1,
            'shard_number' => 1
        ];

        try {

            // 2️⃣ Création collection
            $response = $this->http()->put("{$this->baseUrl}/collections/{$collectionName}", $payload);

            if ($response->successful()) {
                Log::info("Collection {$collectionName} créée avec succès");

                // 3️⃣ Si collection messages → créer index conversation_id
                if (str_starts_with($collectionName, 'conversations')) {

                    $indexResponse = $this->http()->put(
                        "{$this->baseUrl}/collections/{$collectionName}/index",
                        [
                            "field_name" => "conversation_id",
                            "field_schema" => "uuid"
                            // si UUID → "uuid"
                        ]
                    );

                    if ($indexResponse->successful()) {
                        Log::info("Index conversation_id créé pour {$collectionName}");
                    } else {
                        Log::error("Échec création index conversation_id", [
                            'collection' => $collectionName,
                            'status' => $indexResponse->status(),
                            'body' => $indexResponse->body()
                        ]);
                    }
                }

                return true;
            } else {
                Log::error("Échec création collection {$collectionName}", [
                    'status' => $response->status(),
                    'body'   => $response->body()
                ]);
                return false;
            }

        } catch (\Throwable $e) {
            Log::error("Exception lors de la création de la collection {$collectionName}", [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    protected function http()
    {
        return Http::timeout($this->timeout)
            ->withHeaders([
                'api-key' => config('qdrant.api_key'),
                'Content-Type' => 'application/json',
            ]);
    }
}