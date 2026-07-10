<?php

namespace App\Services\rag;

use App\Models\Conversation;
use App\Models\Site;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class ContextCompressor
{
    /**
     * Compresse un ensemble de chunks en un résumé plus court.
     * @param array $chunks
     * @param Site|null $site
     * @param Conversation|null $conversation
     * @return string
     */
    public function compress(array $chunks, ?Site $site = null, ?Conversation $conversation = null): string
    {
        if (empty($chunks)) {
            return '';
        }

        // Construire le texte à résumer
        $combinedText = collect($chunks)
            ->map(fn($c) => $c['text'] ?? '')
            ->implode("\n\n");

        // Prompt pour le mini-LLM
        $prompt = <<<PROMPT
        Tu es un assistant chargé de **résumer de manière concise et structurée** un ensemble d'informations provenant de multiples extraits (chunks) pour fournir uniquement ce qui est pertinent pour répondre à une question.

        Règles :
        - Conserve les informations factuelles importantes.
        - Supprime les répétitions et détails inutiles.
        - Garde le contexte utile pour le LLM final.
        - Résume en français, texte clair et concis.

        Chunks :
        {$combinedText}

        Retourne uniquement le résumé final.
        PROMPT;

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . env('OPENROUTER_API_KEY'),
                'Content-Type' => 'application/json',
            ])->post('https://openrouter.ai/api/v1/chat/completions', [
                'model' => 'meta-llama/llama-3.1-8b-instruct',
                'messages' => [
                    ['role' => 'system', 'content' => $prompt]
                ],
                'temperature' => 0.3,
                'max_tokens' => 250, // mini LLM → résumé court
            ]);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['choices'][0]['message']['content'])) {
                    return trim($data['choices'][0]['message']['content']);
                }
            }

            Log::warning("ContextCompressor: échec de compression", ['response' => $response->body()]);
        } catch (\Exception $e) {
            Log::error("ContextCompressor exception: " . $e->getMessage());
        }

        // fallback : concat simple
        return collect($chunks)->pluck('text')->implode("\n\n");
    }
}
