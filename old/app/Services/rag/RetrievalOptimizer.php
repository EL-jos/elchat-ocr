<?php

namespace App\Services\rag;

use App\Services\queryAnalyzer\QueryPlan;
use Illuminate\Support\Facades\Log;

class RetrievalOptimizer
{
    public function optimize(array $results, QueryPlan $queryPlan): array
    {
        if (empty($results)) {
            return $results;
        }

        $tokens = $this->tokenize($queryPlan->cleanQuery);
        $entities = $queryPlan->entities ?? [];

        foreach ($results as &$chunk) {

            
            $text = strtolower(
                $chunk['text']
                ?? ($chunk['payload']['text'] ?? null)
            );


            /*Log::info("Dans RetrievalOptimizer", [
                "id" => $chunk['id'],
                "source" => $chunk['source'],
                'vector_score' => $chunk['vector_score'],
                'keyword_score' => $chunk['keyword_score'],
                'rrf_score' => $chunk['rrf_score'],
                'text' => $text,
            ]);*/

            $vector = $chunk['vector_score'] ?? 0.0;
            $keyword = $chunk['keyword_score'] ?? 0.0;
            $rrf = $chunk['rrf_score'] ?? 0.0;
            $multi = $chunk['multi_query_bonus'] ?? 0.0;

            $vector = is_numeric($vector) ? (float) $vector : 0.0;
            $keyword = is_numeric($keyword) ? (float) $keyword : 0.0;
            $rrf = is_numeric($rrf) ? (float) $rrf : 0.0;
            $multi = is_numeric($multi) ? (float) $multi : 0.0;

            $baseScore = $chunk['score']; // 🔥 utilise score courant

            $boost = 0;

            // 1️⃣ Boost entités
            foreach ($entities as $entity) {

                if (is_array($entity)) {
                    $entity = implode(' ', $entity);
                }

                if (!is_string($entity) || $entity === '') {
                    continue;
                }

                if (stripos($text, $entity) !== false) {
                    $boost += 0.15;
                }
            }

            foreach ($tokens as $token) {

                if (strlen($token) < 3) continue;

                if (stripos($text, $token) !== false) {
                    $boost += 0.03;
                }

                if (is_numeric($token) && stripos($text, $token) !== false) {
                    $boost += 0.20;
                }
            }

            // 4️⃣ Exact query match
            if (stripos($text, strtolower($queryPlan->cleanQuery)) !== false) {
                $boost += 0.25;
            }

            $boost = tanh($boost);
            // 🔥 APPLY BOOST DIRECTLY
            $chunk['retrieval_boost'] = $boost;
            // 🔥 UPDATE GLOBAL SCORE
            $chunk['score'] =
                $baseScore
                + ($baseScore * $boost * 0.25)
                + ($boost * 0.05);
            //
            $chunk['original_score'] = $baseScore;

        }

        // resort
        usort($results, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $results;
    }

    private function tokenize(string $query): array
    {
        $query = strtolower($query);

        $tokens = preg_split('/[\s,.;:!?()]+/', $query);

        return array_filter($tokens);
    }
}
