<?php

namespace App\Services\ia;

class EntityRelevanceService
{
    public function __construct(
        protected EmbeddingService $embeddingService
    ) {}

    public function filterRelevant(
        array $entities,
        string $question,
        array $queryEntities = []
    ): array {

        if (empty($entities)) {
            return [];
        }

        $questionEmbedding = $this->embeddingService->getEmbedding($question);

        $scored = [];

        foreach ($entities as $entity) {

            $score = $this->computeScore(
                $entity,
                $question,
                $questionEmbedding,
                $queryEntities
            );

            $entity['_score'] = $score;

            $scored[] = $entity;
        }

        $threshold = config('entities.relevance.threshold');

        return collect($scored)
            ->filter(fn($e) => $e['_score'] >= $threshold)
            ->sortByDesc('_score')
            ->take(config('entities.relevance.max_entities'))
            ->map(function ($e) {
                unset($e['_score']);
                return $e;
            })
            ->values()
            ->toArray();
    }

    // ─────────────────────────────

    protected function computeScore(
        array $entity,
        string $question,
        array $questionEmbedding,
        array $queryEntities
    ): float {

        $weights = config('entities.relevance.weights');

        $semantic = $this->semanticScore($entity, $questionEmbedding);
        $keyword  = $this->keywordScore($entity, $question);
        $bonus    = $this->entityBonus($entity, $queryEntities);

        return
            ($semantic * $weights['semantic']) +
            ($keyword  * $weights['keyword']) +
            ($bonus    * $weights['entity_bonus']);
    }

    // ─────────────────────────────
    // 🔥 SEMANTIC SIMILARITY (EMBEDDINGS)
    // ─────────────────────────────

    protected function semanticScore(array $entity, array $questionEmbedding): float
    {
        $text = $this->buildEntityText($entity);

        if (!$text) return 0;

        $entityEmbedding = $this->embeddingService->getEmbedding($text);

        return $this->cosineSimilarity($questionEmbedding, $entityEmbedding);
    }

    // ─────────────────────────────
    // 🔍 KEYWORD MATCH
    // ─────────────────────────────

    protected function keywordScore(array $entity, string $question): float
    {
        $text = strtolower($this->buildEntityText($entity));
        $tokens = $this->tokenize($question);

        if (empty($tokens)) return 0;

        $matches = 0;

        foreach ($tokens as $token) {
            if (str_contains($text, $token)) {
                $matches++;
            }
        }

        return $matches / count($tokens);
    }

    // ─────────────────────────────
    // 🎯 BONUS ENTITIES (QueryPlan)
    // ─────────────────────────────

    protected function entityBonus(array $entity, array $queryEntities): float
    {
        if (empty($queryEntities)) return 0;

        $text = strtolower($this->buildEntityText($entity));

        foreach ($queryEntities as $qe) {

            if (is_string($qe) && str_contains($text, strtolower($qe))) {
                return 1.0;
            }

            if (is_array($qe)) {
                foreach ($qe as $value) {
                    if (is_string($value) && str_contains($text, strtolower($value))) {
                        return 1.0;
                    }
                }
            }
        }

        return 0;
    }

    // ─────────────────────────────

    protected function buildEntityText(array $entity): string
    {
        $fields = config('entities.relevance.fields');

        $parts = [];

        foreach ($fields as $field) {
            if (!empty($entity[$field])) {
                $parts[] = $entity[$field];
            }
        }

        return trim(implode(' ', $parts));
    }

    protected function tokenize(string $text): array
    {
        $text = strtolower($text);

        return array_filter(
            preg_split('/[\s,.;:!?()]+/', $text)
        );
    }

    protected function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $i => $v) {
            $dot += $v * $b[$i];
            $normA += $v * $v;
            $normB += $b[$i] * $b[$i];
        }

        return $dot / (sqrt($normA) * sqrt($normB) + 1e-10);
    }
}
