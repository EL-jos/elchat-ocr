<?php

namespace App\Services\hops;

use App\Contracts\ConversationEngineInterface;
use App\Domain\MCP\Security\ActorContext;
use App\Models\Conversation;
use App\Models\Site;
use App\Services\chunks\ChunkHydrationService;
use App\Services\cta\CTAEngine;
use App\Services\cta\CTARelevanceService;
use App\Services\hybrid\HybridSearchService;
use App\Services\ia\ContextBuilder;
use App\Services\ia\EmbeddingService;
use App\Services\ia\EntityExtractor;
use App\Services\ia\EntityRelevanceService;
use App\Services\ia\EntityResolver;
use App\Services\ia\PromptBuilder;
use App\Services\queryAnalyzer\QueryPlan;
use App\Services\rag\ContextSelectionService;
use App\Services\rag\LLMReRankerService;
use App\ValueObjects\ConversationDirective;
use Illuminate\Support\Facades\Log;

class MultiHopPipelineServiceV2
{
    protected int $maxHops;
    protected float $similarityThreshold = 0.88;
    public function __construct(
        protected EmbeddingService $embeddingService,
        protected HybridSearchService $hybridSearchService,
        protected ChunkHydrationService $chunkHydrationService,
        protected LLMReRankerService $reranker,
        protected ContextSelectionService $contextSelectionService,
        protected ContextBuilder $contextBuilder,
        protected PromptBuilder $promptBuilder,
        protected LLMService $llm,
        protected EntityResolver $entityResolver,
        protected EntityExtractor $entityExtractor,
        protected EntityRelevanceService $entityRelevanceService,
        protected CTAEngine $CTAEngine,
        protected CTARelevanceService $CTARelevanceService,
        protected ConversationEngineInterface $conversationEngine,

    ) {
        $this->maxHops = max(1, (int) config('llm.multi_hop.max_hops', 2));
    }
    public function handle(
        string $question,
        QueryPlan $plan,
        Site $site,
        ?Conversation $conversation = null,
        ?array $history = [],
        ?ConversationDirective $directive = null,
        ?ActorContext $actor = null,
    ): HopResponse {

        Log::info("MULTI-HOP ACTIVATED");

        // 🧠 1. PLAN (LLM)
        $objectives = $this->planObjectives($question, $plan);

        $entitySeeds = array_values(array_filter(
            array_map(fn($e) => $this->normalizeEntityValue($e), $plan->entities ?? []),
            fn($v) => is_string($v) && strlen($v) > 2
        ));

        $seedQueries = array_values(array_filter(array_unique(array_merge(
            $plan->searchQueries ?? [],
            $plan->subQueries ?? [],
            $entitySeeds
        )), fn($v) => is_string($v) && trim($v) !== ''));

        $state = $this->initState($objectives, $plan);

        // 🔥 inject initial evidence BEFORE hops
        if (empty($state['evidence'])) {
            foreach ($seedQueries as $q) {

                $results = $this->retrieve($q, $site, $state, $actor);

                if (empty($results)) continue;

                // 🔥 RERANK léger (clé)
                $results = $this->reranker->rerank(
                    query: $q,
                    chunks: $results,
                    topK: 6
                );

                // 🔥 OPTION SAFE (encore mieux)
                $results = $this->contextSelectionService->select(
                    chunks: $results,
                    queryPlan: $plan,
                    limit: 4,
                    maxTokens: 400
                );

                $results = $this->filterRedundant($results, $state);

                $state['evidence'] = array_merge($state['evidence'], $results);
                $state['visited_ids'] = array_values(array_unique(array_merge(
                    $state['visited_ids'],
                    array_column($results, 'id')
                )));
            }
        }

        for ($hop = 0; $hop < $this->maxHops; $hop++) {

            // 🧠 2. THINK
            $thought = $this->reason($state, $question);

            if ($thought['done'] ?? false) {
                break;
            }
            //Log::info("RESULTAT DE THOUGHT", $thought);

            // 🔍 3. QUERY GENERATION
            $query = $this->generateQuery($thought, $state, $plan);
            /*Log::info("RESULTAT DE GENERATE QUERY", [
                "query" => $query,
            ]);*/

            // 📡 4. RETRIEVE
            $results = $this->retrieve($query, $site, $state, $actor);

            if (empty($results)) continue;
            //Log::info("RESULTAT DE RETRIVE", $results);

            // 🧹 5. RERANK
            $results = $this->reranker->rerank(
                query: $query,
                chunks: $results,
                topK: 12
            );
            //Log::info("RESULTAT DE RERANK", $results);

            // 🧠 6. SELECT CONTEXT
            $results = $this->contextSelectionService->select(
                chunks: $results,
                queryPlan: $plan,
                limit: 6,
                maxTokens: 800
            );
            //Log::info("RESULTAT DE CONTEXT SELECTION", $results);

            // 🚫 7. ANTI-REDUNDANCY
            $results = $this->filterRedundant($results, $state);

            if (empty($results)) continue;
            //Log::info("RESULTAT DE FILTER REDUNDANT", $results);

            // 🧠 8. INGEST
            $state = $this->ingest($state, $results, $thought);
            //Log::info("RESULTAT DE INGEST", $state);

            // 🧾 9. SYNTHÈSE INTERMÉDIAIRE
            $summary = $this->summarizeStep($results, $thought);
            $state['summary'][] = $summary;
            //Log::info("RESULTAT DE SUMMARIZE STEP", $summary);

            // 📊 10. UPDATE STATE
            $state = $this->updateState($state);
            //Log::info("RESULTAT DE UPDATE STATE", $state);

            if ($this->shouldStop($state)) break;
        }

        $finalChunks = $this->entityResolver->resolve(rankedChunks: collect($state['evidence']));

        // 🧾 FINAL CONTEXT
        $finalChunks = $this->deduplicateEvidence($finalChunks);
        //Log::info("RESULTAT DE DEDUPLICATE EVIDENCE", $finalChunks);

        /*// 🔥 recompute lightweight relevance
        $finalChunks = $this->reranker->rerank(
            query: $plan->cleanQuery,
            chunks: $finalChunks,
            topK: 12
        );*/

        $entities = $this->entityExtractor->extract(chunks: $finalChunks);
        /*$entities = $this->entityRelevanceService->filterRelevant(
            entities: $entities,
            question: $plan->cleanQuery,
            queryEntities: $plan->entities ?? []
        );*/
        $ctas = $this->CTAEngine->resolve(site: $site, queryPlan: $plan, conversation: $conversation);
        /*$ctas = $this->CTARelevanceService->filterRelevant(
            ctas: $ctas,
            queryPlan: $plan,
            question: $plan->cleanQuery,
            entities: $entities
        );*/

        // juste avant :  $context = $this->contextBuilder->build($finalChunks);
        if ($directive) {
            $directive = $this->conversationEngine->refine(
                directive: $directive,
                retrievedChunkCount: count($finalChunks),
                plan: $plan,
                // 🆕 ce pipeline dispose déjà d'un score de confiance cumulé
                // (coverage/quality/diversity), plus fiable ici qu'un comptage de
                // chunks qui grossit mécaniquement à chaque hop. On le transmet
                // pour que ClarifyingQuestionPolicy l'utilise à la place du seuil
                // de comptage (voir App\Services\conversation\ClarifyingQuestionPolicy).
                groundingConfidence: $state['confidence']['final'] ?? null,
            );
        }

        $context = $this->contextBuilder->build($finalChunks);

        if (empty(trim($context))) {
            return new HopResponse(
                message: "Je n’ai pas trouvé d’information fiable.",
                ctas: [],
                entities: []
            );
        }
        /*Log::info("RESULTAT DE CONTEXT BUILDER", [
            "context" => $context,
        ]);*/

        // 🧠 FINAL PROMPT (avec résumé structuré 🔥)
        $prompt = $this->promptBuilder->build(
            site: $site,
            question: $question,
            context: $context,
            history: $history,
            conversation: $conversation,
            cats: $ctas,
            entities: $entities,
            directive: $directive,              // 🆕
            extra: [
                'structured_summary' => $state['summary']
            ],
        );
        //Log::info("RESULTAT DE PROMPT BUILDER", $prompt);

        return new HopResponse(
            prompt: $prompt,
            ctas: $ctas,
            entities: $entities,
            context: $context,
        );
    }
    // =====================================================
    // 🧠 PLANNER
    // =====================================================
    protected function planObjectives(string $question, QueryPlan $plan): array
    {
        $prompt = [
            [
                "role" => "system",
                "content" => "Tu es un expert en décomposition de question."
            ],
            [
                "role" => "user",
                "content" => "
Question: {$question}

Décompose en objectifs atomiques.

Retourne STRICTEMENT JSON:

{
  \"objectives\": [
    {\"entity\": \"...\", \"aspect\": \"...\"}
  ]
}
"
            ]
        ];

        $data = $this->llm->chatJson($prompt, [
            'task' => 'multi_hop_objective_extraction',
        ]);

        if (empty($data['objectives']) || !is_array($data['objectives'])) {
            // 🔥 fallback intelligent
            return $this->fallbackObjectives($question, $plan);
        }

        return $data['objectives'];
    }
    // =====================================================
    // 🧠 REASONER
    // =====================================================
    protected function reason(array $state, string $question): array
    {
        $content = <<<CONTENT
Tu es un agent de raisonnement pour un système RAG multi-hop.

Tu dois répondre UNIQUEMENT en JSON valide.

Format obligatoire:

{
  \"done\": boolean,
  \"objective\": {\"entity\": string, \"aspect\": string},
  \"next_query_hint\": string,
  \"reason\": string
}

Règles:
- aucun texte hors JSON
- aucun markdown
- toujours fournir next_query_hint si done=false
- base-toi sur les summaries pour éviter de répéter
- ne re-traite pas un objectif déjà couvert
CONTENT;

        $prompt = [
            [
                "role" => "system",
                "content" => $content
            ],
            [
                "role" => "user",
                "content" => json_encode([
                    "question" => $question,
                    "objectives" => $state['objectives'],
                    "completed" => $state['completed'],
                    "summary" => $state['summary'],
                    "last_objective" => $state['last_objective'] ?? null,
                ])
            ]
        ];

        $thought = $this->llm->chatJson($prompt, [
            'task' => 'multi_hop_thought',
        ]);

        // 🔥 sécurité critique
        if (empty($thought)) {
            return [
                'done' => true,
                'objective' => null,
                'next_query_hint' => null
            ];
        }

        return $thought;
    }
    // =====================================================
    // 🔍 QUERY GENERATOR
    // =====================================================
    protected function generateQuery(array $thought, array $state, QueryPlan $plan): string
    {
        // 🔥 priorité au hint du reasoner
        if (!empty($thought['next_query_hint'])) {
            return $thought['next_query_hint'];
        }

        $prompt = [
            [
                "role" => "system",
                "content" =>
                    "Tu es un générateur de requêtes RAG.
             Tu dois STRICTEMENT respecter le QueryPlan.
             Tu ne dois PAS inventer de nouvelles entités."
            ],
            [
                "role" => "user",
                "content" => json_encode([
                    "thought" => $thought,
                    "plan" => [
                        "searchQueries" => $plan->searchQueries ?? [],
                        "subQueries" => $plan->subQueries ?? [],
                        "entities" => $plan->entities ?? [],
                        "intent" => $plan->intent ?? null
                    ],
                    "used_ids" => $state['visited_ids']
                ])
            ]
        ];

        $query = trim($this->llm->chat($prompt, [
            'task' => 'multi_hop_query',
        ]));

        // 🔥 fallback si vide
        if ($query === '') {
            return $plan->searchQueries[0]
                ?? $plan->subQueries[0]
                ?? $state['objectives'][0]['entity']
                ?? 'information';
        }

        return $query;
    }
    // =====================================================
    // 📡 RETRIEVE
    // =====================================================
    protected function retrieve(string $query, Site $site, array $state, ?ActorContext $actor = null): array
    {
        $embedding = $this->embeddingService->getEmbedding($query);

        $results = $this->hybridSearchService->search(
            query: $query,
            embedding: $embedding,
            siteId: $site->id,
            limit: 20
        );

        $results = $this->chunkHydrationService->hydrate($results, $actor);

        // ❗ anti re-fetch
        return array_filter($results, fn($r)
        => !in_array($r['id'], $state['visited_ids']));
    }
    // =====================================================
    // 🚫 ANTI REDUNDANCY
    // =====================================================
    protected function filterRedundant(array $chunks, array $state): array
    {
        return array_filter($chunks, function ($chunk) use ($state) {

            foreach ($state['evidence'] as $e) {

                if (empty($e['embedding']) || empty($chunk['embedding'])) continue;

                if (
                    $this->cosine($e['embedding'], $chunk['embedding']) > $this->similarityThreshold
                    && $e['source_type'] === $chunk['source_type']
                ) {
                    return false;
                }
            }

            return true;
        });
    }
    // =====================================================
    // 🧠 INGEST
    // =====================================================
    protected function ingest(array $state, array $chunks, array $thought): array
    {
        foreach ($chunks as $c) {
            $state['visited_ids'][] = $c['id'];
            $state['evidence'][] = $c;
        }

        if (!empty($thought['objective'])) {

            $state['completed'][] = [
                'objective' => $thought['objective'],
                'evidence_ids' => array_column($chunks, 'id')
            ];
        }

        $state['last_objective'] = $thought['objective'] ?? null;

        return $state;
    }
    // =====================================================
    // 🧾 SYNTHÈSE INTERMÉDIAIRE
    // =====================================================
    protected function summarizeStep(array $chunks, array $thought): array
    {
        $text = implode("\n", array_column($chunks, 'text'));

        $prompt = [
            [
                "role" => "system",
                "content" => "Résume en faits concrets et utiles."
            ],
            [
                "role" => "user",
                "content" => $text
            ]
        ];

        return [
            'objective' => $thought['objective'] ?? null,
            'summary' => $this->llm->chat($prompt, [
                'task' => 'multi_hop_summary',
            ])
        ];
    }
    // =====================================================
    // 📊 STATE UPDATE
    // =====================================================
    protected function updateState(array $state): array
    {
        // 🧠 1. Coverage via fonction dédiée
        $coverage = $this->computeCoverage($state);

        // 📊 2. Quality
        $quality = collect($state['evidence'])->avg('score') ?? 0;

        // 🌐 3. Diversity
        $diversity = collect($state['evidence'])
                ->pluck('source_type')
                ->unique()
                ->count() / 3;

        // 🧠 4. Confidence globale
        $state['confidence'] = [
            'coverage' => $coverage,
            'quality' => $quality,
            'diversity' => $diversity,
            'final' =>
                ($coverage * 0.5) +
                ($quality * 0.3) +
                ($diversity * 0.2)
        ];

        return $state;
    }
    protected function shouldStop(array $state): bool
    {
        $coverage = $this->computeCoverage($state);

        if ($coverage >= 0.85) return true;

        if ($state['confidence']['final'] > 0.75) return true;

        $stagnation =
            count($state['evidence']) < 5 &&
            count($state['completed']) === 0;

        if ($stagnation && ($state['confidence']['final'] ?? 0) < 0.4) {
            return true;
        }

        return false;
    }

    // =====================================================
    // 🧱 INIT
    // =====================================================
    protected function initState(array $objectives, QueryPlan $plan): array
    {
        return [
            'objectives' => $objectives,
            'completed' => [],
            'visited_ids' => [],
            'evidence' => [],
            'summary' => [],
            'confidence' => [],
        ];
    }
    // =====================================================
    // 🧠 UTILS
    // =====================================================
    protected function cosine(array $a, array $b): float
    {
        $dot = $normA = $normB = 0;

        foreach ($a as $i => $v) {
            $dot += $v * ($b[$i] ?? 0);
            $normA += $v * $v;
            $normB += ($b[$i] ?? 0) ** 2;
        }

        if ($normA == 0 || $normB == 0) return 0;

        return $dot / (sqrt($normA) * sqrt($normB));
    }
    protected function deduplicateEvidence(array $evidence): array
    {
        return collect($evidence)
            ->groupBy('id')
            ->map(fn($g) => $g->first())
            ->values()
            ->toArray();
    }
    protected function fallbackObjectives(string $question, QueryPlan $plan): array
    {
        $objectives = [];

        foreach ($plan->entities ?? [] as $entity) {
            $objectives[] = [
                'entity' => is_array($entity) ? ($entity['value'] ?? '') : $entity,
                'aspect' => 'general'
            ];
        }

        if (empty($objectives)) {
            $objectives[] = [
                'entity' => $question,
                'aspect' => 'general'
            ];
        }

        return $objectives;
    }
    protected function computeCoverage(array $state): float
    {
        $completed = count($state['completed']);
        $total = max(count($state['objectives']), 1);

        return min(1.0, $completed / $total);
    }
    protected function normalizeEntityValue($e): ?string
    {
        if (is_array($e)) {
            return $e['value'] ?? null;
        }

        if (is_string($e)) {
            return $e;
        }

        return null;
    }
}
