<?php

namespace App\Services\rag;

use App\Services\ia\EmbeddingService;
use App\Services\queryAnalyzer\QueryPlan;
use Illuminate\Support\Facades\Log;

class ContextValidator
{
    public function validate(array $chunks, QueryPlan $queryPlan): bool
    {
        if (empty($chunks)) return false;

        $entities = $this->normalizeEntities($queryPlan->entities ?? []);
        if (empty($entities)) {
            $entities[] = strtolower($queryPlan->cleanQuery);
        }

        $relevantChunks = 0;

        foreach ($chunks as $chunk) {

            if (strlen($chunk['text']) < 50) continue;

            $llm = $chunk['llm_score'] ?? null;
            $final = $chunk['final_score'] ?? null;
            $retrieval = $chunk['score'] ?? 0;

            // 🔥 1. LLM = vérité absolue
            if (is_numeric($llm) && $llm >= 0.8) {
                return true;
            }

            // 🔥 2. fallback SI PAS DE LLM
            if (!is_numeric($llm) && is_numeric($final) && $final >= 0.75) {
                return true;
            }

            // 🔥 3. base score hiérarchique
            $baseScore = $chunk['final_score']
                ?? $chunk['llm_score']
                ?? $chunk['retrieval_score']
                ?? 0;

            // 🔥 entity bonus calibré
            $entityBonus = 0;
            foreach ($entities as $e) {
                if (str_contains(
                    strtolower($chunk['text']),
                    trim(strtolower($e))
                )) {
                    $entityBonus = 0.10;
                    break;
                }
            }

            $totalScore = $baseScore + $entityBonus;

            $threshold = 0.55;

            if ($totalScore >= $threshold) {
                $relevantChunks++;
            }

            Log::info("Validator debug (final)", [
                "llm" => $llm,
                "baseScore" => $baseScore,
                "entityBonus" => $entityBonus,
                "total" => $totalScore,
                "threshold" => $threshold,
                "text" => substr($chunk['text'], 0, 100)
            ]);
        }

        return $relevantChunks >= max(1, ceil(count($chunks) * 0.3));
    }

    private function normalizeEntities(array $entities): array
    {
        $normalized = [];

        foreach ($entities as $entity) {
            if (is_array($entity) && isset($entity['value'])) {
                $normalized[] = strtolower($entity['value']);
            } elseif (is_string($entity)) {
                $normalized[] = strtolower($entity);
            }
        }

        return $normalized;
    }
}
