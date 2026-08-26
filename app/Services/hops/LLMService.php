<?php

namespace App\Services\hops;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class LLMService
{
    protected string $primaryModel = 'openai/gpt-4o-mini';

    protected int $maxRetries = 3;

    protected int $timeout = 120;

    public function chat(array $messages, array $options = []): string
    {
        $model = $options['model'] ?? $this->primaryModel;
        $fallbackModel = (string) ($options['fallback_model'] ?? config('mcp.llm.fallback_model', 'deepseek/deepseek-chat-v3.1'));

        // 🔥 NOUVEAU (opt-in) : détection de troncature via finish_reason.
        // Désactivé par défaut => comportement identique pour tous les
        // appelants existants qui ne passent pas cette option.
        $detectTruncation = $options['detect_truncation'] ?? false;

        $maxTokens = $options['max_tokens'] ?? 800;
        $maxTokensCap = $options['max_tokens_cap'] ?? ($maxTokens * 2);

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {

            try {
                $callOptions = $options;
                $callOptions['max_tokens'] = $maxTokens;

                $response = $this->callAPI($messages, $model, $callOptions);

                $choice = $response['choices'][0] ?? null;
                $content = $this->messageContent($choice['message']['content'] ?? null);
                $finishReason = $choice['finish_reason'] ?? null;

                $truncated = $detectTruncation && $finishReason === 'length';

                if ($content && trim($content) !== '' && ! $truncated) {
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
                Log::warning('LLM attempt failed', [
                    'attempt' => $attempt,
                    'model' => $model,
                    'error' => $e->getMessage(),
                ]);

                // Un modèle sans endpoint ne redeviendra pas disponible au
                // prochain retry : basculer immédiatement vers le modèle de
                // secours configuré, au lieu de produire trois erreurs
                // identiques.
                if ($this->isUnavailableModelError($e)) {
                    break;
                }
            }

            usleep(200000 * $attempt); // backoff progressif
        }

        // 🔥 fallback modèle
        if ($model !== $fallbackModel && $fallbackModel !== '') {
            return $this->chat($messages, array_merge($options, [
                'model' => $fallbackModel,
                'fallback_model' => $fallbackModel,
            ]));
        }

        throw new Exception('LLM failed after retries');
    }

    private function isUnavailableModelError(Throwable $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, 'no endpoints found')
            || str_contains($message, 'model not found')
            || str_contains($message, 'unknown model')
            || str_contains($message, 'invalid model')
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

        $response = Http::timeout($this->timeout)
            ->withHeaders([
                'Authorization' => 'Bearer '.config('mcp.llm.api_key', env('OPENROUTER_API_KEY')),
            ])
            ->post('https://openrouter.ai/api/v1/chat/completions', $payload);

        if (! $response->successful()) {
            throw new Exception('LLM API error: '.$response->body());
        }

        return $response->json();
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
