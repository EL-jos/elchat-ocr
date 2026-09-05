<?php

namespace App\Domain\MCP\Orchestration;

use App\Services\hops\LLMModelResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
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
    private readonly string $apiKey;

    private readonly string $model;

    private readonly int $maxRetries;

    private readonly int $timeoutSeconds;

    public function __construct(
        string $apiKey,
        string $model = 'openai/gpt-4.1-mini',
        int $maxRetries = 3,
        int $timeoutSeconds = 45, // 🆕 était 20, trop juste pour une boucle multi-agent
    ) {
        $this->apiKey = $apiKey;
        $this->model = LLMModelResolver::normalize($model, 'openai/gpt-4.1-mini');
        $this->maxRetries = $maxRetries;
        $this->timeoutSeconds = $timeoutSeconds;
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
            $response = Http::withOptions(['stream' => true])
                ->withHeaders([
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

            try {
                $maxResponseBytes = max(262144, min(16 * 1024 * 1024, (int) config('mcp.llm.max_response_bytes', 4194304)));
                [$body, $complete] = $this->readResponseBody($response, $response->successful() ? $maxResponseBytes : 8000);

                if ($response->successful() && $complete) {
                    $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                    $choice = is_array($decoded) ? ($decoded['choices'][0] ?? null) : null;

                    if (!$choice) {
                        Log::warning("MCP OpenRouterToolClient: réponse sans 'choices' (tentative {$attempt})");
                    } else {
                        $message = $choice['message'] ?? [];

                        return [
                            'text' => $message['content'] ?? null,
                            'tool_calls' => $message['tool_calls'] ?? [],
                            'raw_message' => $message, // à réinjecter tel quel dans l'historique pour le tour suivant
                        ];
                    }
                } elseif ($response->successful()) {
                    Log::warning("MCP OpenRouterToolClient: réponse trop volumineuse (tentative {$attempt})");
                } else {
                    Log::warning("MCP OpenRouterToolClient: échec HTTP (tentative {$attempt})", [
                        'status' => $response->status(),
                        'body' => $body,
                    ]);
                }
            } catch (JsonException $exception) {
                Log::warning("MCP OpenRouterToolClient: JSON invalide (tentative {$attempt})", [
                    'error' => $exception->getMessage(),
                ]);
            } finally {
                $response->close();
            }

            if ($attempt < $this->maxRetries) {
                sleep($delay);
                $delay *= 2;
            }
        }

        throw new RuntimeException('Appel LLM (function-calling) échoué après ' . $this->maxRetries . ' tentatives.');
    }

    /** @return array{0: string, 1: bool} contenu lu et réponse entièrement lue */
    private function readResponseBody(\Illuminate\Http\Client\Response $response, int $maxBytes): array
    {
        $stream = $response->toPsrResponse()->getBody();
        $body = '';
        $readLimit = $maxBytes + 1;

        while (! $stream->eof() && strlen($body) < $readLimit) {
            $chunk = $stream->read(min(8192, $readLimit - strlen($body)));
            if ($chunk === '') {
                break;
            }
            $body .= $chunk;
        }

        return [$body, $stream->eof() && strlen($body) <= $maxBytes];
    }
}
