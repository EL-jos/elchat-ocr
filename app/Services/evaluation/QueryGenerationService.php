<?php

namespace App\Services\evaluation;

use App\Models\Chunk;
use App\Models\RagEvaluationQuery;
use App\Services\hops\LLMService;
use App\Services\ia\EmbeddingService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class QueryGenerationService
{
    public function __construct(
        protected LLMService $llm,
        protected EmbeddingService $embedding
    ) {}

    public function generate(string $siteId, array $chunks, int $target = 10): array
    {
        if (empty($chunks)) {
            return [];
        }

        // 1. PREPARE CONTEXT
        $context = $this->buildChunkContext($chunks);

        // 2. GENERATE RAW QUERIES
        $raw = $this->generateWithLLM($context, $target);

        // 3. NORMALIZE
        $queries = $this->normalize($raw);

        // =========================================
        // FILTER INVALID HARD QUERIES
        // =========================================

        $queries = $this->filterInvalidHardQueries($queries);

        // 4. DEDUPLICATION (semantic)
        $queries = $this->deduplicate($queries);

        // =========================================
        // 🔥 NOUVEAU : TOP-UP CIBLÉ (un seul appel complémentaire,
        // uniquement si on est sous la cible après filtre/dédup).
        // Remplace l'ancienne sur-génération systématique (target*2) :
        // ici on ne paie que si c'est réellement nécessaire.
        // =========================================

        if (count($queries) < $target) {
            $queries = $this->topUpQueries($context, $queries, $target);
        }

        // 5. BALANCE INTENTS
        $queries = $this->balanceIntents($queries, $target);

        // 6. LIMIT FINAL SIZE
        return array_slice($queries, 0, $target);
    }

    // =========================================================
    // 🔥 NOUVEAU : TOP-UP CIBLÉ
    // =========================================================
    private function topUpQueries(string $context, array $existing, int $target): array
    {
        $missing = $target - count($existing);

        if ($missing <= 0) {
            return $existing;
        }

        try {
            $additionalRaw = $this->generateWithLLM(
                context: $context,
                target: $missing,
                existingQueries: collect($existing)->pluck('query')->toArray()
            );
        } catch (Throwable $e) {
            Log::warning('Query top-up generation failed, keeping existing queries only', [
                'error' => $e->getMessage(),
                'missing' => $missing,
            ]);

            return $existing;
        }

        $additional = $this->normalize($additionalRaw);
        $additional = $this->filterInvalidHardQueries($additional);

        $merged = array_merge($existing, $additional);

        // re-dédup sémantique sur l'ensemble fusionné (évite doublons
        // entre les questions existantes et les nouvelles)
        return $this->deduplicate($merged);
    }

    // =========================================================
    // FILTER INVALID HARD QUERIES (factorisé, utilisé 2x maintenant)
    // =========================================================
    private function filterInvalidHardQueries(array $queries): array
    {
        return collect($queries)
            ->filter(function ($q) {

                $difficulty = strtolower($q['difficulty'] ?? 'medium');

                $count = count($q['expected_chunk_ids'] ?? []);

                // hard query MUST reference multiple chunks
                if ($difficulty === 'hard') {
                    return $count >= 2;
                }

                return true;
            })
            ->values()
            ->toArray();
    }

    // =========================================================
    // 1. LLM GENERATION (AUDIT PROMPT)
    // =========================================================
    private function generateWithLLM(string $context, int $target, array $existingQueries = []): array
    {
        // budget dynamique : proportionnel au nombre de questions demandées
        $maxTokens = max(500, ($target * 180) + 120);
        $maxTokensCap = (int) round($maxTokens * 1.6);

        $avoidRule = !empty($existingQueries)
            ? "- Do NOT repeat or closely paraphrase any query listed in 'already_generated_do_not_repeat'\n"
            : "";

        $response = $this->llm->chatJson([
            [
                'role' => 'system',
                'content' =>
                    "You are a strict RAG evaluation dataset generator.\n" .
                    "Your job is to generate realistic user queries based ONLY on the provided website content.\n\n" .
                    "- Generate EXACTLY {$target} queries\n" .
                    "- Never generate more than {$target} queries\n" .
                    "Difficulty rules:\n" .
                    "- easy: answer found in one obvious chunk with explicit wording\n" .
                    "- medium: requires combining multiple sentences or interpreting product/service details\n" .
                    "- hard: requires multi-hop reasoning, comparison, implicit understanding, or combining multiple chunks\n" .
                    "Rules:\n" .
                    "- Do NOT invent information not present in the context\n" .
                    "- Queries must be realistic (users would actually ask them)\n" .
                    "- Ensure diversity in intent (informational, transactional, navigation, comparison, troubleshooting)\n" .
                    "- Avoid duplicates or paraphrases\n" .
                    $avoidRule .
                    "- Each query MUST include the chunk ids that contain the answer\n" .
                    "- expected_chunk_ids must reference ONLY ids present in the context\n" .
                    "- Never invent chunk ids\n" .
                    "- hard queries MUST require information from multiple chunks\n" .
                    "- hard queries should usually reference 2 or more expected_chunk_ids\n".
                    "- Output must be JSON only\n"
            ],
            [
                'role' => 'user',
                'content' => json_encode(array_filter([
                    'task' => "Generate evaluation queries for RAG system\n".
                        "- Generate EXACTLY {$target} queries\n" .
                        "- Never generate more than {$target} queries",
                    'target_count' => $target,
                    'context' => $context,
                    // 🔥 NOUVEAU : présent uniquement lors du top-up
                    'already_generated_do_not_repeat' => $existingQueries ?: null,
                    'output_format' => [
                        'queries' => [
                            [
                                'query' => 'string',
                                'intent' => 'informational|transactional|navigation|comparison|support',
                                'difficulty' => 'easy|medium|hard',
                                'expected_chunk_ids' => ['chunk-id-1', 'chunk-id-2'],
                            ]
                        ]
                    ],
                ]))
            ]
        ], [
            'task' => 'rag_query_generation',
            'max_tokens' => $maxTokens,
            'max_tokens_cap' => $maxTokensCap,
            'temperature' => 0.7,
            'detect_truncation' => true,
            'response_format' => true,
            'salvage_array_key' => 'queries',
        ]);

        return $response['queries'] ?? [];
    }

    // =========================================================
    // 2. CONTEXT BUILDER (CHUNK SAMPLING SMART)
    // =========================================================
    private function buildChunkContext(array $chunks): string
    {
        $chunks = array_slice($chunks, 0, 15);

        return collect($chunks)
            ->map(fn($c) => [
                'id' => $c['id'] ?? null,
                'text' => Str::limit(
                    $this->cleanText(
                        $c['text'] ?? $c['payload']['text'] ?? ''
                    ),
                    500
                ),
            ])
            ->filter(fn($c) => strlen($c['text']) > 20)
            ->map(fn($c) => "[{$c['id']}] {$c['text']}")
            ->implode("\n\n");
    }

    // =========================================================
    // 3. NORMALIZATION
    // =========================================================
    private function normalize(array $raw): array
    {
        return collect($raw)
            ->filter(fn($q) => !empty($q['query']))
            ->map(fn($q) => [
                'query' => trim($q['query']),
                'intent' => $q['intent'] ?? 'informational',
                'difficulty' => $q['difficulty'] ?? 'medium',
                'reason' => $q['reason'] ?? null,
                'expected_chunk_ids' => array_values(
                    array_filter(
                        $q['expected_chunk_ids'] ?? []
                    )
                ),
            ])
            ->values()
            ->toArray();
    }

    // =========================================================
    // 4. SEMANTIC DEDUPLICATION
    // =========================================================
    private function deduplicate(array $queries): array
    {
        $unique = [];
        $embeddings = [];

        foreach ($queries as $q) {

            $embedding = $this->embedding->getEmbedding($q['query']);

            foreach ($embeddings as $existing) {
                if ($this->cosine($embedding, $existing) > 0.92) {
                    continue 2;
                }
            }

            $embeddings[] = $embedding;
            $unique[] = $q;
        }

        return $unique;
    }

    // =========================================================
    // 5. INTENT BALANCING (IMPORTANT AUDIT)
    // =========================================================
    private function balanceIntents(array $queries, int $target): array
    {
        $groups = collect($queries)->groupBy('intent');

        $distribution = [
            'informational' => 0.4,
            'transactional' => 0.2,
            'navigation' => 0.15,
            'comparison' => 0.15,
            'support' => 0.1,
        ];

        $final = collect();

        foreach ($distribution as $intent => $ratio) {

            $count = max(1, (int) round($target * $ratio));

            $items = $groups[$intent] ?? collect();

            $final = $final->merge(
                $items->take($count)
            );
        }

        if ($final->count() < $target) {

            $usedQueries = $final->pluck('query')->toArray();

            $remaining = collect($queries)
                ->reject(fn($q) =>
                in_array($q['query'], $usedQueries)
                );

            $missing = $target - $final->count();

            $final = $final->merge(
                $remaining->take($missing)
            );
        }

        return $final
            ->unique('query')
            ->take($target)
            ->values()
            ->toArray();
    }

    // =========================================================
    // UTILS
    // =========================================================
    private function cleanText(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    private function cosine(array $a, array $b): float
    {
        $dot = 0;
        $na = 0;
        $nb = 0;

        foreach ($a as $i => $v) {
            $dot += $v * ($b[$i] ?? 0);
            $na += $v * $v;
            $nb += ($b[$i] ?? 0) ** 2;
        }

        return ($na && $nb) ? $dot / (sqrt($na) * sqrt($nb)) : 0;
    }
}
