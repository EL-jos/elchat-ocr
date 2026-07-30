<?php

namespace App\Domain\MCP\Orchestration;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Client function-calling vers OpenRouter, au même endpoint et avec le même
 * modèle que App\Services\ia\ChatService::callLLM (openai/gpt-4.1-mini),
 * pour ne pas ajouter un second fournisseur LLM au système. Seule
 * différence : ajoute le paramètre 'tools' (function-calling, format
 * OpenAI, supporté nativement par gpt-4.1-mini via OpenRouter) et lit
 * message.tool_calls dans la réponse. Retry/backoff identique au pattern
 * déjà en place dans ChatService pour rester cohérent en observabilité.
 */
class OpenRouterToolClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'openai/gpt-4.1-mini',
        private readonly int $maxRetries = 3,
        private readonly int $timeoutSeconds = 45, // 🆕 était 20, trop juste pour une boucle multi-agent
    ) {
    }

    /**
     * @param array $messages Format OpenAI chat (role/content), avec en plus
     *              d'éventuels messages 'tool' (résultats d'exécution).
     * @param array $tools Format OpenAI: [{type: 'function', function: {name, description, parameters}}]
     * @return array ['text' => ?string, 'tool_calls' => array] — tool_calls vide si le modèle a répondu directement.
     */
    public function send(array $messages, array $tools, string $toolChoice = 'auto', float $temperature = 0.2, int $maxTokens = 500): array
    {
        $delay = 1;

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout($this->timeoutSeconds)->post('https://openrouter.ai/api/v1/chat/completions', [
                'model' => $this->model,
                'messages' => $messages,
                'tools' => $tools,
                'tool_choice' => $toolChoice, // 🆕 était hardcodé sur 'auto'
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
            ]);

            if ($response->successful()) {
                $choice = $response->json('choices.0');

                if (!$choice) {
                    Log::warning("MCP OpenRouterToolClient: réponse sans 'choices' (tentative {$attempt})", ['body' => $response->json()]);
                } else {
                    $message = $choice['message'] ?? [];

                    return [
                        'text' => $message['content'] ?? null,
                        'tool_calls' => $message['tool_calls'] ?? [],
                        'raw_message' => $message, // à réinjecter tel quel dans l'historique pour le tour suivant
                    ];
                }
            } else {
                Log::warning("MCP OpenRouterToolClient: échec HTTP (tentative {$attempt})", [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }

            if ($attempt < $this->maxRetries) {
                sleep($delay);
                $delay *= 2;
            }
        }

        throw new RuntimeException('Appel LLM (function-calling) échoué après ' . $this->maxRetries . ' tentatives.');
    }
}
