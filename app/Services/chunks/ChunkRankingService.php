<?php

namespace App\Services\chunks;

use Illuminate\Support\Facades\Log;

class ChunkRankingService
{
    protected array $sourceWeights = [
        'manuel'      => 0.97,
        'manual'      => 0.97,
        'woocommerce' => 0.95,
        'page'        => 0.95,
        'crawl'       => 0.95,
        'import'      => 0.95,
        'image'       => 0.90,
        'document'    => 0.85,
        'sitemap'     => 0.7,
        'unknown'     => 0.6,
    ];

    public function rank(array $chunks, float $minScore = 0.45, ?int $limit = null): array
    {
        if (empty($chunks)) {
            return [];
        }

        // 🔥 1️⃣ NORMALISATION GLOBALE
        $chunks = $this->normalizeScores($chunks);

        // 🔥 2️⃣ SCORING PRINCIPAL
        $ranked = array_map(function ($chunk) {

            // 🔵 Scores disponibles
            $llm = $chunk['norm_llm'] ?? null;
            $retrieval = $chunk['norm_score'] ?? 0;
            $vector = $chunk['norm_vector'] ?? 0;
            $keyword = $chunk['norm_keyword'] ?? 0;
            $boost = $chunk['retrieval_boost'] ?? 0;
            $multi = $chunk['multi_query_bonus'] ?? 0;

            // 🔥 fallback intelligent
            $base =
                is_numeric($llm)
                    ? (0.65 * $llm + 0.35 * $retrieval)
                    : $retrieval;

            // 🔥 hybrid bonus
            $hybridBoost = ($chunk['source'] ?? '') === 'hybrid' ? 0.05 : 0;

            // 🔥 metadata
            $priority = max(1, (int) ($chunk['priority'] ?? 100));
            $priorityWeight = 1 / (1 + log(1 + $priority));

            $sourceType = $chunk['source_type'] ?? 'unknown';
            $sourceWeight = $this->sourceWeights[$sourceType] ?? 0.6;

            // 🔥 SCORE FINAL MÉTIER (équilibré)
            $final =
                (0.55 * $base) +
                (0.15 * $vector) +
                (0.10 * $keyword) +
                (0.05 * $boost) +
                (0.05 * $multi) +
                (0.05 * $priorityWeight) +
                (0.05 * $sourceWeight) +
                $hybridBoost;

            return array_merge($chunk, [
                'ranking_score' => round($final, 5),
            ]);

        }, $chunks);

        // 🔥 3️⃣ TRI GLOBAL
        usort($ranked, fn($a, $b) => $b['ranking_score'] <=> $a['ranking_score']);

        // 🔥 4️⃣ FILTRAGE INTELLIGENT
        $ranked = $this->filter($ranked, $minScore);

        // 🔥 5️⃣ LIMIT OPTIONNEL
        if ($limit !== null) {
            $ranked = array_slice($ranked, 0, $limit);
        }

        return array_values($ranked);
    }

    // =========================================================
    // 🔵 NORMALISATION
    // =========================================================
    private function normalizeScores(array $chunks): array
    {
        $scores = collect($chunks)->pluck('score')->filter()->values();
        $vectorScores = collect($chunks)->pluck('vector_score')->filter()->values();
        $keywordScores = collect($chunks)->pluck('keyword_score')->filter()->values();
        $llmScores = collect($chunks)->pluck('final_score')->filter()->values();

        $minScore = $scores->min() ?? 0;
        $maxScore = $scores->max() ?? 1;

        $minVector = $vectorScores->min() ?? 0;
        $maxVector = $vectorScores->max() ?? 1;

        $minKeyword = $keywordScores->min() ?? 0;
        $maxKeyword = $keywordScores->max() ?? 1;

        $minLLM = $llmScores->min() ?? 0;
        $maxLLM = $llmScores->max() ?? 1;

        foreach ($chunks as &$c) {

            $c['norm_score'] = $this->normalize($c['score'] ?? 0, $minScore, $maxScore);
            $c['norm_vector'] = $this->normalize($c['vector_score'] ?? 0, $minVector, $maxVector);
            $c['norm_keyword'] = $this->normalize($c['keyword_score'] ?? 0, $minKeyword, $maxKeyword);
            $c['norm_llm'] = $this->normalize($c['final_score'] ?? 0, $minLLM, $maxLLM);
        }

        return $chunks;
    }

    private function normalize($value, $min, $max): float
    {
        if ($max - $min == 0) return 0;
        return ($value - $min) / ($max - $min);
    }

    // =========================================================
    // 🔵 FILTRAGE
    // =========================================================
    private function filter(array $chunks, float $minScore): array
    {
        // 🔥 garde toujours top 3 même si faibles
        $top = array_slice($chunks, 0, 3);

        $rest = array_filter($chunks, fn($c) =>
            ($c['ranking_score'] ?? 0) >= $minScore
        );

        return array_values(array_unique(array_merge($top, $rest), SORT_REGULAR));
    }
}
