<?php

namespace App\Services\rag;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LLMReRankerService
{
    protected int $maxRetries = 5;
    protected int $delaySeconds = 1;

    public function rerank(string $query, array $chunks, int $topK = 10, ?array $queryEmbedding = null): array
    {
        if (empty($chunks)) {
            return [];
        }

        // 🔥 Limite pour coût/perf
        $chunks = array_slice($chunks, 0, 20);

        $documents = array_map(function ($chunk) {
            return $chunk['text'] ?? $chunk['payload']['text'] ?? '';
        }, $chunks);

        $scores = $this->callRerankAPI($query, $documents);

        if (empty($scores)) {
            // 🔥 fallback → retourne ranking initial
            //return array_slice($chunks, 0, $topK);
            Log::warning("LLM rerank failed → using fallback reranker");

            return $this->fallbackRerank($query, $chunks, $topK);
        }

        // 🔥 Merge scores
        $reranked = collect($chunks)
            ->map(function ($chunk, $index) use ($scores, $query) {

                $baseScore = $chunk['score'] ?? 0;
                $retrievalBoost = $chunk['retrieval_boost'] ?? 0;
                $multiBonus = $chunk['multi_query_bonus'] ?? 0;

                $llmScore = $scores[$index] ?? 0;

                $chunk['llm_score'] = $llmScore;

                // 🔥 SCORE FINAL (le cœur du système)

                [$retrievalWeight, $llmWeight] = $this->getFusionWeights($query);

                $chunk['final_score'] =
                    ($retrievalWeight * $baseScore) +
                    ($llmWeight * $llmScore) +
                    (0.05 * $retrievalBoost) +
                    (0.03 * $multiBonus);

                return $chunk;
            })
            ->sortByDesc('final_score') // 🔥 TRÈS IMPORTANT
            ->take($topK)
            ->values()
            ->toArray();

        return $reranked;
    }
    protected function callRerankAPI(string $query, array $documents): array
    {
        $delay = $this->delaySeconds;

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {

                Log::info("Rerank API call (attempt {$attempt})");

                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . env('OPENROUTER_API_KEY'),
                    'Content-Type' => 'application/json',
                ])->post('https://openrouter.ai/api/v1/rerank', [
                    'model' => 'cohere/rerank-v3.5',
                    'query' => $query,
                    'documents' => $documents,
                    'top_n' => min(count($documents), 10),
                ]);

                if (!$response->successful()) {

                    Log::warning("Rerank HTTP error {$response->status()}", [
                        'body' => $response->body()
                    ]);

                    if ($attempt < $this->maxRetries) {
                        sleep($delay);
                        $delay *= 2;
                        continue;
                    }

                    break;
                }

                $data = $response->json();

                if (!isset($data['results'])) {
                    Log::warning("Rerank invalid response structure", $data);
                    continue;
                }

                /**
                 * OpenRouter / Cohere format:
                 * results = [
                 *   { index: 0, relevance_score: 0.98 },
                 *   ...
                 * ]
                 */

                $scores = array_fill(0, count($documents), 0);

                foreach ($data['results'] as $item) {
                    $scores[$item['index']] = $item['relevance_score'] ?? 0;
                }

                return $scores;

            } catch (\Exception $e) {

                Log::error("Rerank exception (attempt {$attempt})", [
                    'error' => $e->getMessage()
                ]);

                if ($attempt < $this->maxRetries) {
                    sleep($delay);
                    $delay *= 2;
                    continue;
                }
            }
        }

        Log::error("Rerank failed after {$this->maxRetries} attempts");

        return [];
    }
    protected function getFusionWeights(string $query): array
    {
        if ($this->isExactQuery($query)) {
            return [0.5, 0.5]; // keyword + LLM important
        }

        return [0.6, 0.4]; // RRF dominant
    }
    protected function isExactQuery(string $query): bool
    {
        return preg_match('/\b[A-Z0-9\-]{4,}\b/', $query)
            && strlen($query) < 40;
    }
    protected function fallbackRerank(string $query, array $chunks, int $topK): array
    {
        $queryTokens = $this->tokenize($query);

        // ✅ 1. EXTRAIRE LES STATS AVANT LE MAP
        $vectorMin = collect($chunks)->pluck('vector_score')->filter()->min() ?? 0;
        $vectorMax = collect($chunks)->pluck('vector_score')->filter()->max() ?? 1;

        $keywordMin = collect($chunks)->pluck('keyword_score')->filter()->min() ?? 0;
        $keywordMax = collect($chunks)->pluck('keyword_score')->filter()->max() ?? 1;

        $multiMin = collect($chunks)->pluck('multi_score')->filter()->min() ?? 0;
        $multiMax = collect($chunks)->pluck('multi_score')->filter()->max() ?? 1;

        // ✅ 2. ENSUITE LE MAP
        return collect($chunks)
            ->map(function ($chunk) use (
                $queryTokens,
                $query,
                $vectorMin, $vectorMax,
                $keywordMin, $keywordMax,
                $multiMin, $multiMax,
                $chunks
            ) {

                $text = strtolower($chunk['text'] ?? $chunk['payload']['text'] ?? '');
                $tokens = $this->tokenize($text);

                // 🔹 lexical
                $overlap = count(array_intersect($queryTokens, $tokens));
                $lexicalScore = $overlap / max(count($queryTokens), 1);

                // 🔹 density
                $length = max(strlen($text), 1);
                $densityScore = min(1, ($overlap * 20) / $length);
                $lengthPenalty = min(1, 200 / max(strlen($text), 1));
                $retrievalBoost = $chunk['retrieval_boost'] ?? 0;

                // 🔹 exact
                $exactBoost = str_contains($text, strtolower($query)) ? 1 : 0;

                // 🔹 raw scores
                $rawMulti = $chunk['multi_query_bonus'] ?? 0;
                $rawScore = $chunk['score'] ?? 0;
                $rawVector = $chunk['vector_score'] ?? 0;
                $rawKeyword = $chunk['keyword_score'] ?? 0;

                // 🔥 3. NORMALISATION ICI
                $multiScore = $this->normalize($rawMulti, $multiMin, $multiMax);
                $vectorScore = $this->normalize($rawVector, $vectorMin, $vectorMax);
                $keywordScore = $this->normalize($rawKeyword, $keywordMin, $keywordMax);

                $scoreMin = collect($chunks)->pluck('score')->min() ?? 0;
                $scoreMax = collect($chunks)->pluck('score')->max() ?? 1;

                $normalizedScore = $this->normalize($rawScore, $scoreMin, $scoreMax);

                // 🔹 hybrid boost
                $hybridBoost = ($chunk['source'] ?? '') === 'hybrid' ? 1 : 0;

                // 🔹 final score
                $final =
                    (0.30 * $normalizedScore) +
                    (0.15 * $vectorScore) +
                    (0.15 * $keywordScore) +
                    (0.10 * $lexicalScore) +
                    (0.10 * $densityScore) +
                    (0.05 * $exactBoost) +
                    (0.05 * $hybridBoost) +
                    (0.10 * $retrievalBoost);

                $chunk['fallback_score'] = $final;

                return $chunk;
            })
            ->sortByDesc('fallback_score')
            ->take($topK)
            ->values()
            ->toArray();
    }
    protected function tokenize(string $text): array
    {
        $text = strtolower($text);

        $text = preg_replace('/[^a-z0-9\s]/', ' ', $text);

        $tokens = explode(' ', $text);

        return array_values(array_filter($tokens, fn($t) => strlen($t) > 2));
    }
    protected function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0;
        $normA = 0;
        $normB = 0;

        foreach ($a as $i => $v) {
            $dot += $v * ($b[$i] ?? 0);
            $normA += $v * $v;
            $normB += ($b[$i] ?? 0) * ($b[$i] ?? 0);
        }

        if ($normA == 0 || $normB == 0) return 0;

        return $dot / (sqrt($normA) * sqrt($normB));
    }
    protected function normalize($value, $min, $max): float
    {
        if ($max - $min == 0) return 1;
        return ($value - $min) / ($max - $min);
    }
}
