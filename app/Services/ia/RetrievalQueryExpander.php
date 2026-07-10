<?php

namespace App\Services\ia;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RetrievalQueryExpander
{
    private const MAX_RETRIES = 5;
    private const MAX_VARIANTS = 5;
    private const HTTP_TIMEOUT = 20;
    private const MODEL = 'deepseek/deepseek-chat';

    /**
     * Generate semantic retrieval variants.
     *
     * Goal:
     * - Improve retrieval recall
     * - Generate embedding-friendly semantic alternatives
     * - Keep meaning stable
     * - Avoid conversational drift
     * - Avoid hallucinated constraints
     */
    public function expand(string $query): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        $messages = [
            [
                'role' => 'system',
                'content' => $this->systemPrompt(),
            ],
            [
                'role' => 'user',
                'content' => $this->userPrompt($query),
            ],
        ];

        $temperature = 0.1;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {

            try {

                Log::info('RetrievalQueryExpander attempt', [
                    'attempt' => $attempt,
                    'query' => $query,
                ]);

                $response = Http::timeout(self::HTTP_TIMEOUT)
                    ->retry(
                        2,
                        400,
                        function ($exception) {

                            if ($exception instanceof RequestException) {

                                $status = optional($exception->response)->status();

                                return in_array($status, [
                                    408,
                                    429,
                                    500,
                                    502,
                                    503,
                                    504
                                ]);
                            }

                            return true;
                        }
                    )
                    ->withHeaders([
                        'Authorization' => 'Bearer ' . env('OPENROUTER_API_KEY'),
                        'Content-Type' => 'application/json',
                    ])
                    ->post(
                        'https://openrouter.ai/api/v1/chat/completions',
                        [
                            'model' => self::MODEL,

                            'messages' => $messages,

                            'temperature' => $temperature,

                            'max_tokens' => 250,
                        ]
                    );

                if (!$response->successful()) {

                    Log::warning('RetrievalQueryExpander HTTP error', [
                        'attempt' => $attempt,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    continue;
                }

                $content = $response->json()['choices'][0]['message']['content'] ?? '';

                $variants = $this->extractVariants($content, $query);

                if (!empty($variants)) {

                    Log::info('RetrievalQueryExpander success', [
                        'attempt' => $attempt,
                        'variants_count' => count($variants),
                    ]);

                    return $variants;
                }

                Log::warning('RetrievalQueryExpander invalid format', [
                    'attempt' => $attempt,
                    'raw_response' => $content,
                ]);

                // Feedback reinforcement
                $messages[] = [
                    'role' => 'system',
                    'content' => 'INVALID OUTPUT FORMAT.
                    Return ONLY valid JSON array of strings.
                    Example:
                    ["query 1","query 2"]',
                ];

                $temperature = max(0.0, $temperature - 0.05);

            } catch (\Throwable $e) {

                Log::error('RetrievalQueryExpander exception', [
                    'attempt' => $attempt,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        Log::warning('RetrievalQueryExpander fallback triggered', [
            'query' => $query,
        ]);

        return $this->fallbackVariants($query);
    }

    /**
     * Strict extraction + normalization.
     */
    private function extractVariants(
        string $response,
        string $originalQuery
    ): array {

        $json = $this->extractJsonArray($response);

        if (!is_array($json)) {
            return [];
        }

        $variants = [];

        foreach ($json as $item) {

            if (!is_string($item)) {
                continue;
            }

            $item = trim($item);

            if ($item === '') {
                continue;
            }

            // Remove dangerous verbosity
            if (mb_strlen($item) > 120) {
                continue;
            }

            // Prevent exact duplicate
            if (mb_strtolower($item) === mb_strtolower($originalQuery)) {
                continue;
            }

            $variants[] = $this->normalizeQuery($item);
        }

        $variants = array_values(array_unique($variants));

        return array_slice($variants, 0, self::MAX_VARIANTS);
    }

    /**
     * Extract first JSON array found.
     */
    private function extractJsonArray(string $response): ?array
    {
        $start = strpos($response, '[');
        $end = strrpos($response, ']');

        if ($start === false || $end === false) {
            return null;
        }

        $json = substr(
            $response,
            $start,
            $end - $start + 1
        );

        $decoded = json_decode($json, true);

        return is_array($decoded)
            ? $decoded
            : null;
    }

    /**
     * Normalize retrieval queries.
     */
    private function normalizeQuery(string $query): string
    {
        $query = trim($query);

        // Collapse spaces
        $query = preg_replace('/\s+/', ' ', $query);

        // Remove trailing punctuation
        $query = preg_replace('/[.,;:!?]+$/', '', $query);

        return trim($query);
    }

    /**
     * Safe deterministic fallback.
     */
    private function fallbackVariants(string $query): array
    {
        $variants = [];

        $variants[] = $query;

        // Lightweight semantic variations
        $variants[] = $query . ' features';
        $variants[] = $query . ' pricing';
        $variants[] = $query . ' documentation';

        return array_values(
            array_unique(
                array_filter($variants)
            )
        );
    }

    private function systemPrompt(): string
    {
        return <<<PROMPT
You are a semantic retrieval optimization engine.

Your task:
Generate alternative retrieval queries for vector search.

GOAL:
Improve semantic recall while preserving the original meaning.

STRICT RULES:
- Return ONLY valid JSON array
- No explanations
- No markdown
- No numbering
- No comments
- Max 5 queries
- Each query must be short
- Embedding optimized
- Keyword rich
- Preserve original intent
- Do NOT hallucinate new constraints
- Do NOT invent products or entities
- Do NOT answer the question

GOOD:
[
  "notion pricing plans",
  "notion subscription cost",
  "notion enterprise pricing"
]

BAD:
"Here are some variants:"
PROMPT;
    }

    private function userPrompt(string $query): string
    {
        return <<<PROMPT
Original query:
{$query}

Generate semantic retrieval variants.
PROMPT;
    }
}
