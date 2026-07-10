<?php

namespace App\Services\chunks;

use App\Models\Chunk;
use App\Models\Message;
use Illuminate\Support\Facades\Log;

class ChunkHydrationService
{
    /**
     * Hydrate les résultats Qdrant avec MySQL
     */
    public function hydrate(array $results): array
    {
        //Log::info("resultat QDRANT", $results);
        if (empty($results)) {
            return [];
        }

        // 1️⃣ Extraire les IDs Qdrant
        $ids = collect($results)
            ->pluck('id')
            ->filter()
            ->values()
            ->toArray();

        if (empty($ids)) {
            return [];
        }

        // 2️⃣ Charger les chunks MySQL
        $chunks = Chunk::whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        // 3️⃣ Fusion Qdrant + MySQL
        $hydrated = [];

        foreach ($results as $result) {
            $chunk = $chunks->get($result['id']);
            if (!$chunk) continue;

            $textContent = '';

            // Décoder JSON
            $decoded = json_decode($chunk->text, true);
            if (is_array($decoded)) {
                // Extraire les contenus existants
                $contents = array_filter(array_map(fn($c) => $c['content'] ?? null, $decoded));
                $textContent = implode('. ', $contents);
            }

            // Fallback texte brut si JSON invalide
            if (empty($textContent)) {
                $textContent = $chunk->text;
            }

            if (strlen($textContent) < 30 && $chunk->source_type !== 'woocommerce') {
                continue;
            }

            if (($chunk->metadata['type'] ?? null) === 'statistical_alias') {
                continue;
            }

            $hydrated[] = [
                'id' => $chunk->id,
                // 🔥 SIGNALS
                'score' => $result['score'],
                'rrf_score' => $result['rrf_score'] ?? null, // si dispo
                'vector_score' => $result['vector_score'] ?? 0.0,
                'keyword_score' => $result['keyword_score'] ?? null,
                'multi_query_bonus' => $result['multi_query_bonus'] ?? null,
                // meta
                'text' => $textContent,
                'priority' => $chunk->priority ?? 100,
                'source_type' => $chunk->source_type ?? 'unknown',
                'metadata' => $chunk->metadata,
                'payload' => $result['payload'] ?? null,
                'source' => $result['source'] ?? null,
                'length' => strlen($textContent),
                'embedding' => $result['embedding'] ?? null,
            ];

            /*Log::info('Hydrated chunk text', [
                'id' => $chunk->id,
                'text_length' => strlen($textContent),
                'text_preview' => substr($textContent, 0, 50),
                'text' => $textContent,
            ]);*/
        }

        return $hydrated;
    }

    public function hydrateMessages(array $qdrantMessageResults): array
    {
        //Log::info("resultat QDRANT Message", $qdrantMessageResults);
        if (empty($qdrantMessageResults)) {
            return [];
        }

        // 1️⃣ Extraire les IDs
        $ids = collect($qdrantMessageResults)
            ->pluck('id')
            ->filter()
            ->values()
            ->toArray();

        if (empty($ids)) {
            return [];
        }

        // 2️⃣ Charger les messages MySQL
        $messages = Message::whereIn('id', $ids)->get()->keyBy('id');

        // 3️⃣ Fusion Qdrant + MySQL
        $hydrated = [];

        foreach ($qdrantMessageResults as $result) {
            $message = $messages->get($result['id']);
            if (!$message) continue;

            $hydrated[] = [
                'id'           => $message->id,
                'text'         => $message->content,
                'vector_score' => $result['score'] ?? 0.0,
                'type'         => 'message',
                'role'         => $message->role,
                'metadata'     => [
                    'created_at' => $message->created_at,
                    'conversation_id' => $message->conversation_id,
                ],
            ];
        }

        return $hydrated;
    }
}
