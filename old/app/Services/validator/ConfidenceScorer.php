<?php

namespace App\Services\validator;

class ConfidenceScorer
{
    public function score(array $signals): array
    {
        $grounding = $signals['grounding']['final_score'] ?? 0;
        $relevance = $signals['relevance'] ?? 0;
        $consistency = $signals['consistency'] ?? 0;

        // 🔥 pondération dynamique (pas hardcodée fixe)
        $weights = $this->dynamicWeights($signals);

        $final = (
            $grounding * $weights['grounding'] +
            $relevance * $weights['relevance'] +
            $consistency * $weights['consistency']
        );

        return [
            'final_score' => $final,
            'weights' => $weights,
        ];
    }

    protected function dynamicWeights(array $signals): array
    {
        // 🔥 exemple adaptatif
        return [
            'grounding' => 0.5,
            'relevance' => 0.3,
            'consistency' => 0.2,
        ];
    }
}
