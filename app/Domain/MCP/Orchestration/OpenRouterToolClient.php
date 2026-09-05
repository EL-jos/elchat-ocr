<?php

namespace App\Domain\MCP\Orchestration;

use App\Domain\LLM\Exceptions\LLMRetryAfter;
use App\Domain\LLM\LLMRetryPolicy;
use App\Services\hops\LLMModelResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
use RuntimeException;

/**
 * Client function-calling vers OpenRouter, au même endpoint et avec le même
 * modèle configuré pour la tâche MCP dans config/llm.php, pour ne pas
 * ajouter un second fournisseur LLM au système. Seule
 * différence : ajoute le paramètre 'tools' (function-calling au format
 * OpenAI) et lit
 * message.tool_calls dans la réponse. Retry/backoff identique au pattern
 * déjà en place dans ChatService pour rester cohérent en observabilité.
 */
class OpenRouterToolClient
{
    private readonly string $apiKey;

    /** @var array<int, string> */
    private readonly array $models;

    private readonly int $maxRetries;

    private readonly int $connectTimeout;

    private readonly int $requestTimeout;

    public function __construct(
        string $apiKey,
        ?string $model = null,
        ?string $fallbackModel = null,
        int $maxRetries = 3,
        ?int $timeoutSeconds = null,
        ?int $connectTimeout = null,
        ?int $requestTimeout = null,
    ) {
        $this->apiKey = $apiKey;
        $this->models = LLMModelResolver::modelsForTask('mcp', array_filter([
            'model' => $model,
            'fallback_model' => $fallbackModel,
        ], static fn ($value) => $value !== null));
        $this->maxRetries = $maxRetries;
        $timeouts = LLMModelResolver::timeoutsForTask('mcp', [
            'connect_timeout' => $connectTimeout,
            // $timeoutSeconds conserve la compatibilité avec l'ancien
            // constructeur et représente le délai total de requête.
            'request_timeout' => $requestTimeout ?? $timeoutSeconds,
        ]);
        $this->connectTimeout = $timeouts['connect_timeout'];
        $this->requestTimeout = $timeouts['request_timeout'];
    }

    /**
     * @param array $messages Format OpenAI chat (role/content), avec en plus
     *              d'éventuels messages 'tool' (résultats d'exécution).
     * @param array $tools Format OpenAI: [{type: 'function', function: {name, description, parameters}}]
     * @return array ['text' => ?string, 'tool_calls' => array] — tool_calls vide si le modèle a répondu directement.
     */
    public function send(array $messages, array $tools, string $toolChoice = 'auto', float $temperature = 0.2, int $maxTokens = 500): array
    {
        $baseUrl = rtrim((string) config('llm.provider.base_url', 'https://openrouter.ai/api/v1'), '/');

        foreach ($this->models as $modelIndex => $model) {
            for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
                $response = null;
                $retryable = true;
                $retryAfterSeconds = null;
                $switchModel = false;

                try {
                    $response = Http::withOptions(['stream' => true])
                        ->withHeaders([
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Content-Type' => 'application/json',
                    ])->connectTimeout($this->connectTimeout)
                        ->timeout($this->requestTimeout)
                        ->post($baseUrl.'/chat/completions', [
                        'model' => $model,
                        'messages' => $messages,
                        'tools' => $tools,
                        'tool_choice' => $toolChoice,
                        'temperature' => $temperature,
                        'max_tokens' => $maxTokens,
                    ]);

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
                        $status = $response->status();
                        $retryable = LLMRetryPolicy::isRetryableStatus($status);
                        $retryAfterSeconds = LLMRetryAfter::parse($response->header('Retry-After'));

                        Log::warning("MCP OpenRouterToolClient: échec HTTP (tentative {$attempt})", [
                            'status' => $status,
                            'retryable' => $retryable,
                            'body' => mb_strcut($body, 0, 1000),
                        ]);

                        // 4xx déterministe, notamment les schémas invalides,
                        // doit passer immédiatement au modèle de secours.
                        $switchModel = ! $retryable
                            || ($status !== 429 && $this->isUnavailableModelError($status, $body));
                    }
                } catch (JsonException $exception) {
                    $retryable = true;
                    Log::warning("MCP OpenRouterToolClient: JSON invalide (tentative {$attempt})", [
                        'error' => $exception->getMessage(),
                        'retryable' => true,
                    ]);
                } catch (\Throwable $exception) {
                    $retryable = LLMRetryPolicy::isRetryableException($exception);
                    Log::warning("MCP OpenRouterToolClient: exception réseau (tentative {$attempt})", [
                        'error' => $exception->getMessage(),
                        'retryable' => $retryable,
                    ]);
                } finally {
                    $response?->close();
                }

                if ($switchModel || ! $retryable) {
                    break;
                }

                if ($attempt < $this->maxRetries) {
                    $delayMilliseconds = LLMRetryPolicy::delayMilliseconds($attempt, $retryAfterSeconds);
                    if ($delayMilliseconds > 0) {
                        usleep($delayMilliseconds * 1000);
                    }
                }
            }

            if (isset($this->models[$modelIndex + 1])) {
                Log::warning('MCP bascule vers le modèle de secours', [
                    'failed_model' => $model,
                    'fallback_model' => $this->models[$modelIndex + 1],
                ]);
            }
        }

        throw new RuntimeException('Appel LLM (function-calling) échoué après ' . $this->maxRetries . ' tentatives.');
    }

    private function isUnavailableModelError(int $status, string $body): bool
    {
        $message = mb_strtolower($body);

        return $status === 404
            || str_contains($message, 'no endpoints found')
            || str_contains($message, 'model not found')
            || str_contains($message, 'unknown model')
            || str_contains($message, 'invalid model');
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
