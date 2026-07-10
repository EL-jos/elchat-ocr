<?php

namespace App\Services\rag;

use Illuminate\Support\Facades\Log;

class ContextSelectionService
{
    public function select(array $chunks, $queryPlan, int $limit = 8, int $maxTokens = 1200): array
    {
        if (empty($chunks)) return [];

        // 1️⃣ SANITIZE
        $chunks = $this->sanitize($chunks);

        // 2️⃣ SIGNAL BALANCING (OK)
        $chunks = $this->balanceSignals($chunks);

        // 3️⃣ RELEVANCE SIMPLIFIÉE (IMPORTANT)
        $chunks = $this->computeRelevanceScore($chunks, $queryPlan);

        // 4️⃣ TRI INITIAL (MMR SEED ONLY)
        usort($chunks, fn($a, $b) =>
            ($b['relevance_score'] ?? 0) <=> ($a['relevance_score'] ?? 0)
        );

        // 5️⃣ MMR (SOURCE OF TRUTH)
        $lambda = $this->resolveLambda($queryPlan);
        $selected = $this->mmrSelect($chunks, $limit, $lambda);

        // 6️⃣ HARD DIVERSITY (OK)
        $selected = $this->enforceHardDiversity($selected);

        // 7️⃣ TOKEN CONTROL (OK)
        $selected = $this->applyTokenBudget($selected, $maxTokens);

        return $selected;
    }
    // =========================================================
    // 🔵 SANITIZATION
    // =========================================================
    private function sanitize(array $chunks): array
    {
        $chunks = array_values(array_filter($chunks, fn($c) =>
            isset($c['text']) && trim($c['text']) !== ''
        ));

        foreach ($chunks as $c) {
            if (!isset($c['id'])) {
                Log::warning("Chunk missing ID", $c);
            }
        }

        return $chunks;
    }
    // =========================================================
    // 🔵 NORMALIZATION
    // =========================================================
    private function normalizeScores(array $chunks): array
    {
        $finalScores = [];
        $llmScores = [];
        $keywordScores = [];

        foreach ($chunks as $c) {
            if (is_numeric($c['final_score'] ?? null)) $finalScores[] = $c['final_score'];
            if (is_numeric($c['llm_score'] ?? null)) $llmScores[] = $c['llm_score'];
            if (is_numeric($c['keyword_score'] ?? null)) $keywordScores[] = $c['keyword_score'];
        }

        $maxFinal = $finalScores ? max($finalScores) : 1;
        $maxLLM = $llmScores ? max($llmScores) : 1;

        foreach ($chunks as &$c) {

            $c['final_score'] = $c['final_score'] ?? 0;
            $c['llm_score'] = $c['llm_score'] ?? 0;
            $c['vector_score'] = $c['vector_score'] ?? 0;
            $c['keyword_score'] = $this->normalizeKeywordScore($c['keyword_score'] ?? 0);

            $c['norm_final'] = $maxFinal > 0 ? $c['final_score'] / $maxFinal : 0;
            $c['norm_llm'] = $maxLLM > 0 ? $c['llm_score'] / $maxLLM : 0;
            $c['norm_vector'] = max(0, min(1, $c['vector_score']));
        }

        return $chunks;
    }
    // =========================================================
    // 🔵 RELEVANCE SCORE (IMPORTANT)
    // =========================================================
    private function computeRelevanceScore(array $chunks, $queryPlan): array
    {
        $kwWeight = match($queryPlan->intent ?? null) {
            'navigation' => 0.25,
            'pricing' => 0.25,
            'transactional' => 0.20,
            'comparison' => 0.15,
            default => 0.10
        };

        foreach ($chunks as &$c) {

            $vector = $c['balanced_vector'] ?? $c['vector_score'] ?? 0;
            $keyword = $c['balanced_keyword'] ?? $c['keyword_score'] ?? 0;

            // ❗ IMPORTANT: final_score n’est PAS réinjecté massivement
            $llm = $c['llm_score'] ?? 0;

            $c['relevance_score'] =
                (0.50 * ($c['final_score'] ?? 0)) +
                (0.35 * $llm) +
                (0.10 * $vector) +
                (0.05 * $keyword);
        }

        return $chunks;
    }
    // =========================================================
    // 🔵 MMR SELECTION
    // =========================================================
    private function mmrSelect(array $chunks, int $limit, float $lambda): array
    {
        $selected = [];
        $selectedIds = [];

        if (empty($chunks)) return [];

        $selected[] = $chunks[0];
        $selectedIds[$chunks[0]['id']] = true;

        while (count($selected) < $limit) {

            $best = null;
            $bestScore = -INF;

            foreach ($chunks as $chunk) {

                if (isset($selectedIds[$chunk['id']])) continue;

                $maxSim = 0;

                foreach ($selected as $s) {
                    $sim = $this->semanticSimilarity($chunk, $s);
                    $maxSim = max($maxSim, $sim);
                }

                $relevance = $chunk['relevance_score'];
                // 🔥 hard filter
                if ($maxSim > 0.92 && $relevance < 0.5) continue;

                $score =
                    ($lambda * $relevance)
                    - ((1 - $lambda) * $maxSim)
                    - $this->sourcePenalty($chunk, $selected)
                    - $this->typePenalty($chunk, $selected);

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $chunk;
                }
            }

            if (!$best) break;

            $selected[] = $best;
            $selectedIds[$best['id']] = true;
        }

        return $selected;
    }
    // =========================================================
    // 🔵 HARD DIVERSITY
    // =========================================================
    private function enforceHardDiversity(array $chunks): array
    {
        $result = [];

        foreach ($chunks as $chunk) {

            foreach ($result as $r) {
                if ($this->semanticSimilarity($chunk, $r) > 0.90) {
                    continue 2;
                }
            }

            $result[] = $chunk;
        }

        return $result;
    }
    // =========================================================
    // 🔵 TOKEN BUDGET
    // =========================================================
    private function applyTokenBudget(array $chunks, int $maxTokens): array
    {
        $total = 0;
        $result = [];

        foreach ($chunks as $chunk) {

            $length = min(300, strlen($chunk['text']) / 4);

            if (($total + $length) > $maxTokens) break;

            $result[] = $chunk;
            $total += $length;
        }

        return $result;
    }
    // =========================================================
    // 🔵 LAMBDA
    // =========================================================
    private function resolveLambda($queryPlan): float
    {
        return match($queryPlan->intent ?? null) {
            'comparison' => 0.5,
            'information' => 0.65,
            'pricing' => 0.8,
            'navigation' => 0.9,
            'transactional' => 0.85,
            'support' => 0.75,
            'lead' => 0.7,
            'booking' => 0.8,
            'download' => 0.85,
            default => 0.7
        };
    }
    // =========================================================
    // 🔵 SIMILARITY
    // =========================================================
    private function semanticSimilarity($a, $b): float
    {
        if (isset($a['embedding'], $b['embedding'])) {
            return $this->cosineSimilarity($a['embedding'], $b['embedding']);
        }

        similar_text(strtolower($a['text']), strtolower($b['text']), $p);
        return $p / 100;
    }
    // =========================================================
    // 🔵 PENALTIES
    // =========================================================
    private function sourcePenalty($chunk, $selected): float
    {
        $count = 0;

        foreach ($selected as $s) {
            if (($s['source_type'] ?? null) === ($chunk['source_type'] ?? null)) {
                $count++;
            }
        }

        return $count * 0.05;
    }
    private function typePenalty($chunk, $selected): float
    {
        $type = $chunk['metadata']['type'] ?? null;
        if (!$type) return 0;

        $count = 0;

        foreach ($selected as $s) {
            if (($s['metadata']['type'] ?? null) === $type) {
                $count++;
            }
        }

        return $count * 0.05;
    }
    // =========================================================
    // 🔵 KEYWORD NORMALIZATION
    // =========================================================
    private function normalizeKeywordScore($score): float
    {
        if (!is_numeric($score)) return 0;

        $score = (float) $score;

        return max(0, min(1, tanh($score)));
    }
    // =========================================================
    // 🔵 COSINE
    // =========================================================
    private function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0; $normA = 0; $normB = 0;

        foreach ($a as $i => $v) {
            $dot += $v * ($b[$i] ?? 0);
            $normA += $v ** 2;
            $normB += ($b[$i] ?? 0) ** 2;
        }

        if ($normA == 0 || $normB == 0) return 0;

        return $dot / (sqrt($normA) * sqrt($normB));
    }
    private function balanceSignals(array $chunks): array
    {
        foreach ($chunks as &$chunk) {

            $vector = $chunk['vector_score'] ?? 0;
            $keyword = $chunk['keyword_score'] ?? 0;

            if ($keyword === 0 && $vector === 0) {
                continue;
            }

            if ($keyword > 0.85 && $vector < 0.25) {
                $keyword *= 0.9;
            }

            if ($vector > 0.85 && $keyword < 0.25) {
                $vector *= 0.9;
            }

            $chunk['balanced_vector'] = $vector;
            $chunk['balanced_keyword'] = $keyword;
        }

        return $chunks;
    }
}
