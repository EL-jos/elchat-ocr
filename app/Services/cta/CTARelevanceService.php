<?php

namespace App\Services\cta;

class CTARelevanceService
{
    public function filterRelevant(
        array $ctas,
        object $queryPlan,
        string $question,
        array $entities = []
    ): array {

        if (empty($ctas)) return [];

        $scored = [];

        foreach ($ctas as $cta) {

            $score = $this->computeScore(
                $cta,
                $queryPlan,
                $question,
                $entities
            );

            $cta['_score'] = $score;

            $scored[] = $cta;
        }

        $threshold = config('cta.relevance.threshold');

        return collect($scored)
            ->filter(fn($c) => $c['_score'] >= $threshold)
            ->sortByDesc('_score')
            ->take(config('cta.relevance.max_ctas'))
            ->map(function ($c) {
                unset($c['_score']);
                return $c;
            })
            ->values()
            ->toArray();
    }

    // ─────────────────────────────

    protected function computeScore(
        array $cta,
        object $queryPlan,
        string $question,
        array $entities
    ): float {

        $weights = config('cta.relevance.weights');

        return
            ($this->intentScore($cta, $queryPlan) * $weights['intent']) +
            ($this->keywordScore($cta, $question) * $weights['keyword']) +
            ($this->contextScore($cta, $queryPlan) * $weights['context']) +
            ($this->entityScore($cta, $entities) * $weights['entity']);
    }

    // 🧠 INTENT MATCH

    protected function intentScore(array $cta, object $queryPlan): float
    {
        $ctaIntents = $cta['rules']['intents'] ?? [];

        if (empty($ctaIntents) || empty($queryPlan->intent)) {
            return 0;
        }

        return in_array($queryPlan->intent, $ctaIntents) ? 1.0 : 0.0;
    }

    // 🔍 KEYWORD MATCH

    protected function keywordScore(array $cta, string $question): float
    {
        $keywords = $cta['rules']['keywords'] ?? [];

        if (empty($keywords)) return 0;

        $question = strtolower($question);

        foreach ($keywords as $keyword) {
            if (str_contains($question, strtolower($keyword))) {
                return 1.0;
            }
        }

        return 0;
    }

    // 🌍 CONTEXT MATCH

    protected function contextScore(array $cta, object $queryPlan): float
    {
        $contexts = $cta['rules']['contexts'] ?? [];

        if (empty($contexts) || empty($queryPlan->context)) {
            return 0;
        }

        return in_array($queryPlan->context, $contexts) ? 1.0 : 0.0;
    }

    // 🔗 ENTITY MATCH

    protected function entityScore(array $cta, array $entities): float
    {
        $ctaEntities = $cta['rules']['entities'] ?? [];

        if (empty($ctaEntities) || empty($entities)) {
            return 0;
        }

        foreach ($entities as $entity) {

            $type = $entity['type'] ?? null;

            if ($type && in_array($type, $ctaEntities)) {
                return 1.0;
            }
        }

        return 0;
    }
}
