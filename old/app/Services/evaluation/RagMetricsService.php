<?php

namespace App\Services\evaluation;

class RagMetricsService
{
    public function __construct(
        protected LLMEvaluationJudge $LLMEvaluationJudge
    ) {}
    public function computeRetrieval(
        array $retrieved,
        array $expected,
        int $k = 10
    ): array {

        $ids = collect($retrieved)
            ->sortByDesc('score')
            ->take($k)
            ->pluck('id')
            ->values()
            ->toArray();

        return [
            'recall' => $this->recall($ids, $expected),
            'mrr' => $this->mrr($ids, $expected),
            'ndcg' => $this->ndcg($ids, $expected),
        ];
    }

    private function recall($ids, array $expected){
        return count(array_intersect($ids, $expected)) / max(count($expected), 1);
    }
    private function mrr(array $retrieved, array $expected): float
    {
        foreach ($retrieved as $i => $id) {
            if (in_array($id, $expected)) {
                return 1 / ($i + 1);
            }
        }

        return 0;
    }
    private function ndcg(array $retrieved, array $expected): float
    {
        $dcg = 0;

        foreach ($retrieved as $i => $id) {
            $relevance = in_array($id, $expected) ? 1 : 0;
            $dcg += $relevance / log($i + 2, 2);
        }

        $idcg = 0;
        for ($i = 0; $i < count($expected); $i++) {
            $idcg += 1 / log($i + 2, 2);
        }

        return $idcg > 0 ? $dcg / $idcg : 0;
    }
    public function evaluateAnswer(string $query, string $answer, array $context): array
    {
        return $this->LLMEvaluationJudge->score($query, $answer, $context);
    }
}
