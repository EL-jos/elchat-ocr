<?php

namespace App\Services\hops;

use App\Domain\LLM\Exceptions\LLMProviderException;
use App\Domain\LLM\LLMRetryPolicy;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class LLMService
{
    protected int $maxRetries;

    protected int $connectTimeout;

    protected int $requestTimeout;

    protected ?string $lastUsedModel = null;

    public function __construct()
    {
        $this->maxRetries = max(1, (int) config('llm.provider.max_retries', 3));
        $timeouts = LLMModelResolver::timeoutsForTask('chat');
        $this->connectTimeout = $timeouts['connect_timeout'];
        $this->requestTimeout = $timeouts['request_timeout'];
    }

    public function chat(array $messages, array $options = []): string
    {
        $this->lastUsedModel = null;
        $task = isset($options['task']) ? (string) $options['task'] : null;
        $modelOptions = $options;

        // Compatibilité avec les appels historiques et les tests qui règlent
        // encore mcp.llm.fallback_model directement.
        if ($task === null && ! array_key_exists('fallback_model', $modelOptions)) {
            $legacyFallback = config('mcp.llm.fallback_model');
            if (is_string($legacyFallback) && trim($legacyFallback) !== '') {
                $modelOptions['fallback_model'] = $legacyFallback;
            }
        }

        $models = $task !== null
            ? LLMModelResolver::modelsForTask($task, $modelOptions)
            : LLMModelResolver::modelsForTask('chat', $modelOptions);

        if ($models === []) {
            throw new Exception('Aucun modèle LLM configuré pour la tâche.'.($task ? " {$task}" : ''));
        }

        // 🔥 NOUVEAU (opt-in) : détection de troncature via finish_reason.
        // Désactivé par défaut => comportement identique pour tous les
        // appelants existants qui ne passent pas cette option.
        $detectTruncation = $options['detect_truncation'] ?? false;

        $maxTokens = $options['max_tokens'] ?? 800;
        $maxTokensCap = $options['max_tokens_cap'] ?? ($maxTokens * 2);
        $timeouts = LLMModelResolver::timeoutsForTask($task ?? 'chat', $options);

        foreach ($models as $modelIndex => $model) {
            for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
                $retryAfterSeconds = null;

                try {
                    $callOptions = $options;
                    $callOptions['max_tokens'] = $maxTokens;
                    $callOptions['connect_timeout'] = $timeouts['connect_timeout'];
                    $callOptions['request_timeout'] = $timeouts['request_timeout'];

                    $response = $this->callAPI($messages, $model, $callOptions);

                    $choice = $response['choices'][0] ?? null;
                    $content = $this->messageContent($choice['message']['content'] ?? null);
                    $finishReason = $choice['finish_reason'] ?? null;

                    $truncated = $detectTruncation && $finishReason === 'length';

                    if ($content && trim($content) !== '' && ! $truncated) {
                        $this->lastUsedModel = $model;

                        return trim($content);
                    }

                    if ($truncated) {
                        Log::warning('LLM response truncated (finish_reason=length), retrying with higher max_tokens', [
                            'attempt' => $attempt,
                            'model' => $model,
                            'previous_max_tokens' => $maxTokens,
                        ]);

                        // augmente le budget pour la prochaine tentative, plafonné
                        $maxTokens = min((int) round($maxTokens * 1.5), $maxTokensCap);
                    }

                } catch (Throwable $e) {
                    $retryable = LLMRetryPolicy::isRetryableException($e);
                    Log::warning('LLM attempt failed', [
                        'attempt' => $attempt,
                        'model' => $model,
                        'task' => $task,
                        'error' => $e->getMessage(),
                        'status' => $e instanceof LLMProviderException ? $e->status : null,
                        'retryable' => $retryable,
                        'provider_error' => $e instanceof LLMProviderException ? $e->bodyExcerpt() : null,
                    ]);

                    // Un modèle sans endpoint ne redeviendra pas disponible au
                    // prochain retry. Les 4xx (sauf 429) sont également des
                    // erreurs déterministes : basculer immédiatement vers le
                    // modèle de secours sans consommer les retries.
                    if (! $retryable || $this->isUnavailableModelError($e)) {
                        break;
                    }

                    $retryAfterSeconds = LLMRetryPolicy::retryAfterSeconds($e);
                }

                if ($attempt < $this->maxRetries) {
                    $delayMilliseconds = LLMRetryPolicy::delayMilliseconds($attempt, $retryAfterSeconds);
                    if ($delayMilliseconds > 0) {
                        usleep($delayMilliseconds * 1000);
                    }
                }
            }

            if (isset($models[$modelIndex + 1])) {
                Log::warning('Basculement vers le modèle LLM de secours', [
                    'task' => $task,
                    'failed_model' => $model,
                    'fallback_model' => $models[$modelIndex + 1],
                ]);
                $maxTokens = $options['max_tokens'] ?? 800;
            }
        }

        throw new Exception('LLM failed after retries');
    }

    /**
     * Génère une réponse avec function calling tout en utilisant le même
     * registre de modèles, les mêmes retries et les mêmes timeouts que chat().
     *
     * Cette méthode ne modifie pas le contrat historique de chat() : les
     * appelants qui ont besoin de lire les tool_calls utilisent explicitement
     * cette API et les autres continuent de recevoir une string.
     *
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $tools
     * @return array{
     *     text: ?string,
     *     tool_calls: array<int, array<string, mixed>>,
     *     raw_message: array<string, mixed>,
     *     model: string,
     *     finish_reason: mixed
     * }
     */
    public function chatWithTools(array $messages, array $tools, array $options = []): array
    {
        if ($tools === []) {
            throw new Exception('Au moins un outil est requis pour un appel LLM avec function calling.');
        }

        $this->lastUsedModel = null;
        $task = isset($options['task']) && (string) $options['task'] !== ''
            ? (string) $options['task']
            : 'chat';
        $models = LLMModelResolver::modelsForTask($task, $options);

        if ($models === []) {
            throw new Exception("Aucun modèle LLM configuré pour la tâche {$task}");
        }

        $initialMaxTokens = (int) ($options['max_tokens'] ?? 800);
        $maxTokens = $initialMaxTokens;
        $maxTokensCap = (int) ($options['max_tokens_cap'] ?? ($maxTokens * 2));
        $detectTruncation = (bool) ($options['detect_truncation'] ?? false);
        $timeouts = LLMModelResolver::timeoutsForTask($task, $options);

        foreach ($models as $modelIndex => $model) {
            for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
                $retryAfterSeconds = null;

                try {
                    $callOptions = [
                        ...$options,
                        'task' => $task,
                        'max_tokens' => $maxTokens,
                        'connect_timeout' => $timeouts['connect_timeout'],
                        'request_timeout' => $timeouts['request_timeout'],
                        'tools' => $tools,
                        // Le mode unifié doit laisser le modèle répondre en
                        // texte lorsqu'aucune action n'est pertinente.
                        'tool_choice' => $options['tool_choice'] ?? 'auto',
                    ];

                    $response = $this->callAPI($messages, $model, $callOptions);
                    $choice = $response['choices'][0] ?? null;

                    if (! is_array($choice) || ! is_array($choice['message'] ?? null)) {
                        throw new Exception('LLM API returned an invalid tool-calling response shape.');
                    }

                    $message = $choice['message'];
                    $content = $this->messageContent($message['content'] ?? null);
                    $toolCalls = is_array($message['tool_calls'] ?? null)
                        ? array_values(array_filter($message['tool_calls'], 'is_array'))
                        : [];
                    $finishReason = $choice['finish_reason'] ?? null;

                    // Un appel d'outil est une réponse valide même si content
                    // est null, ce qui est le format normal des providers.
                    if ($toolCalls !== []) {
                        $this->lastUsedModel = $model;

                        return [
                            'text' => $content !== null ? trim($content) : null,
                            'tool_calls' => $toolCalls,
                            'raw_message' => $message,
                            'model' => $model,
                            'finish_reason' => $finishReason,
                        ];
                    }

                    // Aucun outil : la réponse texte reste compatible avec le
                    // flux standard. Une réponse vide est retentée comme dans
                    // chat(), afin de ne jamais propager un contenu invalide.
                    $truncated = $detectTruncation && $finishReason === 'length';
                    if ($content !== null && trim($content) !== '' && ! $truncated) {
                        $this->lastUsedModel = $model;

                        return [
                            'text' => trim($content),
                            'tool_calls' => [],
                            'raw_message' => $message,
                            'model' => $model,
                            'finish_reason' => $finishReason,
                        ];
                    }

                    if ($truncated) {
                        Log::warning('LLM tool-calling response truncated (finish_reason=length), retrying with higher max_tokens', [
                            'attempt' => $attempt,
                            'model' => $model,
                            'previous_max_tokens' => $maxTokens,
                        ]);
                        $maxTokens = min((int) round($maxTokens * 1.5), $maxTokensCap);
                    }
                } catch (Throwable $e) {
                    $retryable = LLMRetryPolicy::isRetryableException($e);
                    Log::warning('LLM tool-calling attempt failed', [
                        'attempt' => $attempt,
                        'model' => $model,
                        'task' => $task,
                        'error' => $e->getMessage(),
                        'status' => $e instanceof LLMProviderException ? $e->status : null,
                        'retryable' => $retryable,
                        'provider_error' => $e instanceof LLMProviderException ? $e->bodyExcerpt() : null,
                    ]);

                    if (! $retryable || $this->isUnavailableModelError($e)) {
                        break;
                    }

                    $retryAfterSeconds = LLMRetryPolicy::retryAfterSeconds($e);
                }

                if ($attempt < $this->maxRetries) {
                    $delayMilliseconds = LLMRetryPolicy::delayMilliseconds($attempt, $retryAfterSeconds);
                    if ($delayMilliseconds > 0) {
                        usleep($delayMilliseconds * 1000);
                    }
                }
            }

            if (isset($models[$modelIndex + 1])) {
                Log::warning('Basculement vers le modèle LLM de secours pour function calling', [
                    'task' => $task,
                    'failed_model' => $model,
                    'fallback_model' => $models[$modelIndex + 1],
                ]);
                $maxTokens = $initialMaxTokens;
            }
        }

        throw new Exception('LLM tool-calling failed after retries');
    }

    /**
     * Génère un embedding pour chaque entrée via le registre central.
     *
     * @return array<int, array<int, float>>
     */
    public function embeddings(array $inputs, array $options = []): array
    {
        $this->lastUsedModel = null;
        $task = (string) ($options['task'] ?? 'embedding');
        $models = LLMModelResolver::modelsForTask($task, $options);
        $timeouts = LLMModelResolver::timeoutsForTask($task, $options);
        $lastError = 'unknown error';

        if ($models === []) {
            throw new Exception("Aucun modèle LLM configuré pour la tâche {$task}");
        }

        foreach ($models as $modelIndex => $model) {
            for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
                $retryAfterSeconds = null;

                try {
                    $response = $this->postJson('/embeddings', [
                        'model' => $model,
                        'input' => $inputs,
                    ], [
                        ...$options,
                        ...$timeouts,
                    ]);

                    if (! $response->successful()) {
                        throw LLMProviderException::fromResponse(
                            $response,
                            mb_strcut($response->body(), 0, 8000),
                            'Embedding',
                        );
                    }

                    $data = $response->json();
                    if (! is_array($data) || ! isset($data['data']) || ! is_array($data['data'])) {
                        throw new Exception('Embedding API returned an invalid response shape.');
                    }

                    $embeddings = [];
                    foreach ($data['data'] as $position => $item) {
                        if (! is_array($item) || ! isset($item['embedding']) || ! is_array($item['embedding'])) {
                            throw new Exception('Embedding API returned an invalid embedding item.');
                        }

                        $embeddings[(int) ($item['index'] ?? $position)] = array_map(
                            static fn ($value): float => (float) $value,
                            $item['embedding'],
                        );
                    }

                    ksort($embeddings);
                    $this->lastUsedModel = $model;

                    return array_values($embeddings);
                } catch (Throwable $exception) {
                    $lastError = $exception->getMessage();
                    $retryable = LLMRetryPolicy::isRetryableException($exception);
                    Log::warning('Embedding LLM attempt failed', [
                        'task' => $task,
                        'attempt' => $attempt,
                        'model' => $model,
                        'error' => $lastError,
                        'status' => $exception instanceof LLMProviderException ? $exception->status : null,
                        'retryable' => $retryable,
                        'provider_error' => $exception instanceof LLMProviderException ? $exception->bodyExcerpt() : null,
                    ]);

                    if (! $retryable || $this->isUnavailableModelError($exception)) {
                        break;
                    }

                    $retryAfterSeconds = LLMRetryPolicy::retryAfterSeconds($exception);
                }

                if ($attempt < $this->maxRetries) {
                    $delayMilliseconds = LLMRetryPolicy::delayMilliseconds($attempt, $retryAfterSeconds);
                    if ($delayMilliseconds > 0) {
                        usleep($delayMilliseconds * 1000);
                    }
                }
            }

            if (isset($models[$modelIndex + 1])) {
                Log::warning('Basculement vers le modèle embedding de secours', [
                    'task' => $task,
                    'failed_model' => $model,
                    'fallback_model' => $models[$modelIndex + 1],
                ]);
            }
        }

        throw new Exception('Embedding LLM failed after retries: '.$lastError);
    }

    /**
     * Réordonne les documents avec le endpoint de reranking configuré.
     *
     * @return array<int, float> scores indexés comme $documents
     */
    public function rerank(string $query, array $documents, array $options = []): array
    {
        $this->lastUsedModel = null;
        $task = (string) ($options['task'] ?? 'rag_rerank');
        $models = LLMModelResolver::modelsForTask($task, $options);
        $timeouts = LLMModelResolver::timeoutsForTask($task, $options);
        $topN = min(count($documents), (int) ($options['top_n'] ?? 10));
        $lastError = 'unknown error';

        if ($models === []) {
            throw new Exception("Aucun modèle LLM configuré pour la tâche {$task}");
        }

        foreach ($models as $modelIndex => $model) {
            for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
                $retryAfterSeconds = null;

                try {
                    $response = $this->postJson('/rerank', [
                        'model' => $model,
                        'query' => $query,
                        'documents' => $documents,
                        'top_n' => $topN,
                    ], [
                        ...$options,
                        ...$timeouts,
                    ]);

                    if (! $response->successful()) {
                        throw LLMProviderException::fromResponse(
                            $response,
                            mb_strcut($response->body(), 0, 8000),
                            'Rerank',
                        );
                    }

                    $data = $response->json();
                    if (! is_array($data) || ! isset($data['results']) || ! is_array($data['results'])) {
                        throw new Exception('Rerank API returned an invalid response shape.');
                    }

                    $scores = array_fill(0, count($documents), 0.0);
                    foreach ($data['results'] as $item) {
                        if (! is_array($item) || ! isset($item['index'])) {
                            continue;
                        }

                        $index = (int) $item['index'];
                        if ($index >= 0 && $index < count($scores)) {
                            $scores[$index] = (float) ($item['relevance_score'] ?? 0);
                        }
                    }

                    $this->lastUsedModel = $model;

                    return $scores;
                } catch (Throwable $exception) {
                    $lastError = $exception->getMessage();
                    $retryable = LLMRetryPolicy::isRetryableException($exception);
                    Log::warning('Rerank LLM attempt failed', [
                        'task' => $task,
                        'attempt' => $attempt,
                        'model' => $model,
                        'error' => $lastError,
                        'status' => $exception instanceof LLMProviderException ? $exception->status : null,
                        'retryable' => $retryable,
                        'provider_error' => $exception instanceof LLMProviderException ? $exception->bodyExcerpt() : null,
                    ]);

                    if (! $retryable || $this->isUnavailableModelError($exception)) {
                        break;
                    }

                    $retryAfterSeconds = LLMRetryPolicy::retryAfterSeconds($exception);
                }

                if ($attempt < $this->maxRetries) {
                    $delayMilliseconds = LLMRetryPolicy::delayMilliseconds($attempt, $retryAfterSeconds);
                    if ($delayMilliseconds > 0) {
                        usleep($delayMilliseconds * 1000);
                    }
                }
            }

            if (isset($models[$modelIndex + 1])) {
                Log::warning('Basculement vers le modèle de reranking de secours', [
                    'task' => $task,
                    'failed_model' => $model,
                    'fallback_model' => $models[$modelIndex + 1],
                ]);
            }
        }

        throw new Exception('Rerank LLM failed after retries: '.$lastError);
    }

    public function lastUsedModel(): ?string
    {
        return $this->lastUsedModel;
    }

    private function isUnavailableModelError(Throwable $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, 'no endpoints found')
            || str_contains($message, 'http 404')
            || str_contains($message, 'status 404')
            || str_contains($message, 'model not found')
            || str_contains($message, 'unknown model')
            || str_contains($message, 'invalid model')
            || str_contains($message, 'not a valid model')
            || str_contains($message, '"code":404');
    }

    protected function callAPI(array $messages, string $model, array $options): array
    {
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? 0.2,
            'max_tokens' => $options['max_tokens'] ?? 800,
        ];

        // 🔥 NOUVEAU (opt-in) : mode JSON strict côté provider.
        // Ajouté au payload UNIQUEMENT si l'appelant le demande via
        // $options['response_format']. N'affecte aucun autre appelant.
        if (! empty($options['response_format'])) {
            $payload['response_format'] = is_array($options['response_format'])
                ? $options['response_format']
                : ['type' => 'json_object'];
        }

        if (! empty($options['tools']) && is_array($options['tools'])) {
            $payload['tools'] = $options['tools'];
        }

        if (array_key_exists('tool_choice', $options)) {
            $payload['tool_choice'] = $options['tool_choice'];
        }

        // Les chaînes MCP sont exécutées séquentiellement afin de préserver
        // l'ordre des effets métier et le mécanisme de confirmation. Les
        // appelants classiques ne changent pas de comportement puisqu'ils ne
        // transmettent pas cette option.
        if (array_key_exists('parallel_tool_calls', $options)) {
            $payload['parallel_tool_calls'] = (bool) $options['parallel_tool_calls'];
        }

        $response = $this->postJson('/chat/completions', $payload, $options, true);

        $maxResponseBytes = max(262144, min(16 * 1024 * 1024, (int) config(
            'llm.provider.max_response_bytes',
            config('mcp.llm.max_response_bytes', 4194304),
        )));

        try {
            if (! $response->successful()) {
                [$body] = $this->readResponseBody($response, 8000);
                throw LLMProviderException::fromResponse(
                    $response,
                    mb_strcut($body, 0, 8000),
                    'LLM',
                );
            }

            [$body, $complete] = $this->readResponseBody($response, $maxResponseBytes);
            if (! $complete) {
                throw new Exception('LLM response exceeded the configured memory limit.');
            }

            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new Exception('LLM API returned invalid JSON.', previous: $exception);
        } finally {
            $response->close();
        }

        if (! is_array($decoded)) {
            throw new Exception('LLM API returned an invalid response shape.');
        }

        return $decoded;
    }

    protected function postJson(string $path, array $payload, array $options = [], bool $stream = false): \Illuminate\Http\Client\Response
    {
        $baseUrl = rtrim((string) config('llm.provider.base_url', 'https://openrouter.ai/api/v1'), '/');
        $apiKey = (string) (config('mcp.llm.api_key') ?: config('llm.provider.api_key', env('OPENROUTER_API_KEY')));

        $request = Http::connectTimeout((int) ($options['connect_timeout'] ?? $this->connectTimeout))
            ->timeout((int) ($options['request_timeout'] ?? $options['timeout'] ?? $this->requestTimeout));

        if ($stream) {
            $request = $request->withOptions(['stream' => true]);
        }

        $headers = [
            'Authorization' => 'Bearer '.$apiKey,
            'Content-Type' => 'application/json',
        ];

        if (isset($options['headers']) && is_array($options['headers'])) {
            $headers = array_merge($headers, $options['headers']);
        }

        return $request
            ->withHeaders($headers)
            ->post($baseUrl.$path, $payload);
    }

    /**
     * Lit une réponse HTTP par petits blocs afin qu'une réponse web-search
     * anormalement volumineuse ne soit jamais copiée entièrement en mémoire.
     *
     * @return array{0: string, 1: bool} contenu lu et réponse entièrement lue
     */
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

    // =====================================================
    // 🧠 JSON SAFE PARSER (CRITIQUE)
    // =====================================================

    public function chatJson(array $messages, array $options = []): array
    {
        $response = $this->chat($messages, $options);

        return $this->safeJsonDecode($response, $options);
    }

    protected function safeJsonDecode(string $text, array $options = []): array
    {
        // nettoyage agressif
        $text = trim($text);
        $maxJsonChars = max(10000, min(2 * 1024 * 1024, (int) ($options['max_json_chars'] ?? config(
            'llm.provider.max_json_chars',
            config('mcp.llm.max_json_chars', 1048576),
        ))));
        if (strlen($text) > $maxJsonChars) {
            Log::warning('LLM JSON response discarded because it exceeded the configured size limit', [
                'bytes' => strlen($text),
                'max_bytes' => $maxJsonChars,
            ]);

            return [];
        }

        // remove markdown
        $text = preg_replace('/```json|```/', '', $text);

        // tentative directe
        $decoded = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        // fallback extraction JSON
        if (preg_match('/\{.*\}/s', $text, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        // 🔥 NOUVEAU (opt-in) : sauvetage partiel d'un tableau JSON tronqué.
        // Tenté UNIQUEMENT si l'appelant fournit 'salvage_array_key'.
        // Récupère les objets déjà complets (ex: questions 1,2,3) au lieu
        // de tout jeter à cause d'un objet coupé en fin de flux.
        $salvageKey = $options['salvage_array_key'] ?? null;

        if ($salvageKey) {
            $salvaged = $this->salvagePartialArray($text, $salvageKey);

            if (! empty($salvaged)) {
                Log::warning('JSON parse failed, salvaged partial array', [
                    'key' => $salvageKey,
                    'recovered_count' => count($salvaged),
                ]);

                return [$salvageKey => $salvaged];
            }
        }

        Log::warning('JSON parse failed', ['text' => $text]);

        return [];
    }

    /**
     * OpenRouter normally returns text content as a string. Some models may
     * return a list of content blocks instead, especially when server tools
     * are enabled. Normalize both forms for every existing caller.
     */
    private function messageContent(mixed $content): ?string
    {
        if (is_string($content)) {
            return $content;
        }

        if (! is_array($content)) {
            return null;
        }

        $parts = [];
        foreach ($content as $block) {
            if (is_string($block)) {
                $parts[] = $block;
            } elseif (is_array($block) && isset($block['text']) && is_string($block['text'])) {
                $parts[] = $block['text'];
            }
        }

        return $parts ? implode("\n", $parts) : null;
    }

    /**
     * Extrait les objets JSON complets et valides d'un tableau nommé ($key)
     * même si la réponse globale est tronquée / invalide.
     * Ignore silencieusement le dernier objet s'il est incomplet.
     */
    private function salvagePartialArray(string $text, string $key): array
    {
        if (! preg_match('/"'.preg_quote($key, '/').'"\s*:\s*\[/', $text, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $start = $m[0][1] + strlen($m[0][0]); // juste après le '['

        $objects = [];
        $depth = 0;
        $objStart = null;
        $inString = false;
        $escape = false;

        $length = strlen($text);

        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];

            if ($inString) {
                if ($escape) {
                    $escape = false;
                } elseif ($char === '\\') {
                    $escape = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;

                continue;
            }

            if ($char === '{') {
                if ($depth === 0) {
                    $objStart = $i;
                }
                $depth++;

                continue;
            }

            if ($char === '}') {
                $depth--;
                if ($depth === 0 && $objStart !== null) {
                    $candidate = substr($text, $objStart, $i - $objStart + 1);
                    $decodedObj = json_decode($candidate, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $objects[] = $decodedObj;
                    }
                    $objStart = null;
                }

                continue;
            }

            if ($char === ']' && $depth === 0) {
                break; // fin normale du tableau
            }
        }

        return $objects;
    }
}
