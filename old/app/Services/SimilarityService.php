<?php

namespace App\Services;

class SimilarityService
{
    public function cosine(array $vecA, array $vecB): float
    {
        $dot = 0; $normA = 0; $normB = 0;
        foreach ($vecA as $i => $val) {
            $dot += $val * ($vecB[$i] ?? 0);
            $normA += $val ** 2;
            $normB += ($vecB[$i] ?? 0) ** 2;
        }
        //return $normA && $normB ? $dot / (sqrt($normA) * sqrt($normB)) : 0;
        return ($normA > 0 && $normB > 0)
            ? $dot / (sqrt($normA) * sqrt($normB))
            : 0.0;
    }
}
