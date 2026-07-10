<?php

namespace App\Services\hops;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class LLMService
{
    protected string $primaryModel = 'openai/gpt-4o-mini';
    protected string $fallbackModel = 'anthropic/claude-3.5-haiku';
    protected int $maxRetries = 3;
    protected int $timeout = 120;

    public function chat(array $messages, array $options = []): string
    {
        /*Log::info("DANS CHAT JSON => CHAT", [
            "messages" => $messages,
            "options" => $options
        ]);*/

        $model = $options['model'] ?? $this->primaryModel;

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {

            try {
                $response = $this->callAPI($messages, $model, $options);

                $content = $response['choices'][0]['message']['content'] ?? null;

                if ($content && trim($content) !== '') {
                    return trim($content);
                }

            } catch (Throwable $e) {
                Log::warning("LLM attempt failed", [
                    'attempt' => $attempt,
                    'error' => $e->getMessage()
                ]);
            }

            usleep(200000 * $attempt); // backoff progressif
        }

        // 🔥 fallback modèle
        if ($model !== $this->fallbackModel) {
            return $this->chat($messages, array_merge($options, [
                'model' => $this->fallbackModel
            ]));
        }

        throw new Exception("LLM failed after retries");
    }

    protected function callAPI(array $messages, string $model, array $options): array
    {
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? 0.2,
            'max_tokens' => $options['max_tokens'] ?? 800,
        ];

        //Log::info("max_tokens et temperature", $payload);

        $response = Http::timeout($this->timeout)
            ->withHeaders([
                'Authorization' => 'Bearer ' . env('OPENROUTER_API_KEY'),
            ])
            ->post('https://openrouter.ai/api/v1/chat/completions', $payload);

        if (!$response->successful()) {
            throw new Exception("LLM API error: " . $response->body());
        }

        return $response->json();
    }

    // =====================================================
    // 🧠 JSON SAFE PARSER (CRITIQUE)
    // =====================================================

    public function chatJson(array $messages, array $options = []): array
    {
        /*Log::info("DANS CHAT JSON", [
           "messages" => $messages,
            "options" => $options
        ]);*/
        $response = $this->chat($messages, $options);

        return $this->safeJsonDecode($response);
    }

    protected function safeJsonDecode(string $text): array
    {
        // 🔥 nettoyage agressif
        $text = trim($text);

        // remove markdown
        $text = preg_replace('/```json|```/', '', $text);

        // tentative directe
        $decoded = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        // 🔥 fallback extraction JSON
        if (preg_match('/\{.*\}/s', $text, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        Log::warning("JSON parse failed", ['text' => $text]);

        return [];
    }
}
