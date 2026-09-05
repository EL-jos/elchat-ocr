<?php

namespace App\Services\rag;

use App\Models\Conversation;
use App\Models\Site;
use App\Services\hops\LLMService;
use Illuminate\Support\Facades\Log;

class ContextCompressor
{
    public function __construct(private readonly LLMService $llm) {}

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
            return trim($this->llm->chat([
                    ['role' => 'system', 'content' => $prompt]
                ], [
                'task' => 'rag_context_compression',
                'temperature' => 0.3,
                'max_tokens' => 250, // mini LLM → résumé court
            ]));
        } catch (\Exception $e) {
            Log::error("ContextCompressor exception: " . $e->getMessage());
        }

        // fallback : concat simple
        return collect($chunks)->pluck('text')->implode("\n\n");
    }
}
