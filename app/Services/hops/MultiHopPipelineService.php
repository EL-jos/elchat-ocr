<?php

namespace App\Services\hops;

use App\Models\Conversation;
use App\Models\Site;
use App\Models\UnansweredQuestion;
use App\Services\chunks\ChunkHydrationService;
use App\Services\chunks\ChunkRankingService;
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
use App\Services\rag\RetrievalOptimizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MultiHopPipelineService
{
    protected int $maxRetries = 3;
    protected QueryPlan $queryPlan;
    public function __construct(
        protected EmbeddingService $embeddingService,
        protected HybridSearchService $hybridSearchService,
        protected ChunkHydrationService $chunkHydrationService,
        protected LLMReRankerService $LLMReRankerService,
        protected RetrievalOptimizer $retrievalOptimizer,
        protected ChunkRankingService $chunkRankingService,
        protected ContextSelectionService $contextSelectionService,
        protected EntityResolver $entityResolver,
        protected EntityExtractor $entityExtractor,
        protected ContextBuilder $contextBuilder,
        protected EntityRelevanceService $entityRelevanceService,
        protected CTAEngine $CTAEngine,
        protected CTARelevanceService $CTARelevanceService,
        protected PromptBuilder $promptBuilder,
    ){}
    public function handle(string $question, QueryPlan $plan, Site $site, Conversation $conversation = null, array $history = [],): HopResponse
    {
        $state = $this->initState($plan);

        $this->ensureObjectiveEmbeddings($state);

        $maxHops = 4;

        for ($i = 0; $i < $maxHops; $i++) {

            $state = $this->computeCoverage($state);

            if ($this->isComplete($state)) {
                break;
            }

            $nextObjective = $this->selectNextObjective($state);

            $query = $this->buildQueryFromObjective($nextObjective, $state, $question);

            $results = $this->retrieve($query, $site, $state, $plan);

            if (empty($results)) break;

            // 🔥 PIPELINE RETRIEVAL
            $results = $this->retrievalOptimizer->optimize($results, $plan);
            $results = array_values(array_filter($results, function ($c) {
                if (!isset($c['id'])) {
                    Log::warning('Chunk sans ID supprimé', ['chunk' => $c]);
                    return false;
                }
                return true;
            }));
            //Log::info("RESULTAT DE Optimizer", $results);

            $results = $this->LLMReRankerService->rerank(
                query: $query,
                chunks: $results,
                topK: 15
            );
            // 3️⃣ Fallback si rien trouvé
            if (empty($results)) {
                UnansweredQuestion::create([
                    'site_id' => $site->id,
                    'question' => $question,
                ]);

                //dd(empty($qdrantResults), $qdrantResults, $site->id, floatval($site->settings->min_similarity_score));
                return new HopResponse(
                    message: "Je n’ai pas trouvé cette information dans les données de notre entreprise.
                        N’hésitez pas à nous préciser votre besoin ou à nous contacter directement.",
                    ctas: [],
                    entities: []
                );
            }
            $results = array_values(array_filter($results, function ($c) {
                if (!isset($c['id'])) {
                    Log::warning('Chunk sans ID supprimé', ['chunk' => $c]);
                    return false;
                }
                return true;
            }));
            //Log::info("RESULTAT DE RERANKER", $results);

            $results = $this->chunkRankingService->rank(
                chunks: $results,
                minScore: $site->settings->min_similarity_score,
                limit: 10
            );
            $results = array_values(array_filter($results, function ($c) {
                if (!isset($c['id'])) {
                    Log::warning('Chunk sans ID supprimé', ['chunk' => $c]);
                    return false;
                }
                return true;
            }));
            //Log::info("RESULTAT DE RANKER", $results);

            $results = $this->contextSelectionService->select(
                chunks: $results,
                queryPlan: $plan,
                limit: 8,
                maxTokens: 1200
            );

            $results = array_values(array_filter($results, function ($c) {
                if (!isset($c['id'])) {
                    Log::warning('Chunk sans ID supprimé', ['chunk' => $c]);
                    return false;
                }
                return true;
            }));
            //Log::info("RESULTAT DE SELECTION", $results);

            // 🔥 evidence uniquement ici
            $state = $this->ingestEvidence($state, $results, $nextObjective);

            $state = $this->evaluateState($state, $results);

            $state['hop']++;
        }

        // =====================================================
        // 🔥 POST-PROCESS GLOBAL (IMPORTANT CHANGEMENT)
        // =====================================================

        $finalChunks = $this->getFinalChunks($state);
        Log::info("ENTITY LAYER GLOBAL", [
            "finalChunks" => $finalChunks,
        ]);

        // 🔥 ENTITY LAYER GLOBAL
        $resolved = $this->entityResolver->resolve(collect($finalChunks));

        $entities = $this->entityExtractor->extract($resolved);
        $entities = $this->entityRelevanceService->filterRelevant(
            $entities,
            $plan->cleanQuery,
            $plan->entities ?? []
        );

        $ctas = $this->CTAEngine->resolve($site, $plan, $conversation);
        Log::info("Resolved CTAs:", ['ctas' => $ctas]);
        $ctas = $this->CTARelevanceService->filterRelevant(
            $ctas,
            $plan,
            $plan->cleanQuery,
            $entities
        );

        // 🔥 CONTEXT FINAL GLOBAL
        $context = $this->contextBuilder->build($resolved);
        Log::info("CONTEXT BUILDER", [
            'context' => $context
        ]);
        if (trim($context) === '') {
            return new HopResponse(
                message: "Je n’ai pas d’information fiable à ce sujet pour le moment.",
                ctas: [],
                entities: []
            );
        }

        $state['entities'] = $entities;
        $state['ctas'] = $ctas;
        $state['context'] = $context;

        $prompt = $this->promptBuilder->build(
            site: $site,
            question: $question,
            context: $context,
            history: $history,
            conversation: $conversation,
            cats: $ctas ?? [],
            entities: $entities ?? []
        );
        Log::info("Prompt Payload:", [
            "prompt" => $prompt,
        ]);

        return new HopResponse(
            prompt: $prompt,
            ctas: $ctas,
            entities: $entities,
            context: $context
        );

    }
    protected function initState(QueryPlan $plan): array
    {
        return [
            'hop' => 0,
            'visited_ids' => [],

            // 🔥 compréhension
            'entities' => $plan->entities ?? [],
            'intent' => $plan->intent,
            'constraints' => $plan->constraints ?? [],

            // 🔥 reasoning tracking
            'objectives' => $this->extractObjectives($plan),
            'covered_objectives' => [],
            'missing_objectives' => [],

            // 🔥 knowledge
            'evidence' => [],
            'evidence_by_source' => [],
            'keywords' => [],

            // 🔥 diagnostics
            // remplace ton confidence simple
            'confidence' => [
                'coverage' => 0,
                'quality' => 0,
                'diversity' => 0,
            ],

            'objective_embeddings' => [],
            'evidence_by_objective' => [],
            'objective_scores' => [],
        ];
    }
    protected function extractObjectives(QueryPlan $plan): array
    {
        $objectives = [];

        if ($plan->intent === 'comparison') {
            foreach ($plan->entities as $entity) {

                if (is_array($entity)) {
                    $entity = $entity['type']
                        ?? $entity['value']
                        ?? null;
                }

                if (!$entity || is_array($entity)) {
                    continue;
                }

                $objectives[] = "features:" . strtolower($entity);
            }

            foreach ($plan->constraints as $c) {

                if (is_array($c)) {
                    $value = $c['value'] ?? null;
                } elseif (is_object($c)) {
                    $value = $c->value ?? null;
                } else {
                    $value = $c;
                }

                if (is_string($value) && $value !== '') {
                    $objectives[] = "constraint:$value";
                }
            }

            $objectives[] = "comparison";
        }

        if ($plan->intent === 'informational') {
            $objectives[] = "explanation";
        }

        return $objectives;
    }
    protected function computeCoverage(array $state): array
    {
        $covered = [];

        foreach ($state['objectives'] as $obj) {

            if ($this->hasEvidenceFor($obj, $state)) {
                $covered[] = $obj;
            }
        }

        $state['covered_objectives'] = $covered;
        $state['missing_objectives'] = array_diff($state['objectives'], $covered);

        return $state;
    }
    protected function selectNextObjective(array $state): ?string
    {
        if (empty($state['missing_objectives'])) {
            return null;
        }

        // priorité aux contraintes
        foreach ($state['missing_objectives'] as $obj) {
            if (str_starts_with($obj, 'constraint:')) {
                return $obj;
            }
        }

        return $state['missing_objectives'][0];
    }
    protected function buildQueryFromObjective(string $objective, array $state, string $question): string
    {
        if (str_starts_with($objective, 'features:')) {
            $entity = str_replace('features:', '', $objective);
            return "$entity caractéristiques détails";
        }

        if (str_starts_with($objective, 'constraint:')) {
            $constraint = str_replace('constraint:', '', $objective);
            return "information sur $constraint";
        }

        if ($objective === 'comparison') {
            return implode(' vs ', $state['entities']) . " comparaison";
        }

        return $question;
    }
    protected function retrieve(string $query, Site $site, array $state, QueryPlan $plan): array
    {
        $queries = $plan->subQueries ?? [];
        if (empty($queries)) {
            foreach ($plan->entities as $entity) {
                $queries[] = is_array($entity)
                    ? ($entity['value'] ?? null)
                    : $entity;
            }
        }
        array_unshift($queries, $query);

        $resultsMap = [];
        foreach ($queries as $q){

            $embedding = $this->embeddingService->getEmbedding($q);

            $partial = $this->hybridSearchService->search(
                query: $q,
                embedding: $embedding,
                siteId: $site->id,
                limit: 20,
            );
            foreach ($partial as $item) {

                $id = $item['id'];

                if (!isset($resultsMap[$id])) {
                    $resultsMap[$id] = $item;

                    $resultsMap[$id]['hit_count'] = 0;
                    $resultsMap[$id]['multi_query_score'] = 0;
                }

                // 🔥 count occurrences
                $resultsMap[$id]['hit_count']++;

                // 🔥 léger bonus par présence multi-query
                $resultsMap[$id]['multi_query_score'] += 1;
            }
        }

        $resultsHybridSearch = collect($resultsMap)
            ->map(function ($item) {

                $hits = $item['hit_count'] ?? 1;

                $item['multi_query_bonus'] = min(0.15, log(1 + $hits) * 0.05);

                // 🔥 UPDATE GLOBAL SCORE
                $item['score'] = ($item['rrf_score'] ?? 0) + $item['multi_query_bonus'];

                return $item;
            })
            //->sortByDesc('score')
            ->values()
            ->toArray();
        //Log::info("APRES RECHERCHE HYBRIDE APRES CLASSEMENT", $resultsHybridSearch);

        $hydrated = $this->chunkHydrationService->hydrate($resultsHybridSearch);
        // 🔥 diversité par source / entité
        return collect($hydrated)
            ->groupBy(fn($r) => $r['metadata']['entity'] ?? 'generic')
            ->map(fn($group) => collect($group)->take(2))
            ->flatten(1)
            ->values()
            ->toArray();
    }
    protected function ingestEvidence(array $state, array $results, string $objective): array
    {
        foreach ($results as $chunk) {

            $state['visited_ids'][] = $chunk['id'];

            $state['keywords'] = array_slice(
                array_unique(array_merge(
                    $state['keywords'],
                    $this->extractKeywords($chunk['text'])
                )),
                0,
                50
            );

            $state['evidence'][] = [
                'id' => $chunk['id'],
                'text' => $chunk['text'],
                'score' => $chunk['score'] ?? 0,
                'vector_score' => $chunk['vector_score'] ?? 0,
                'keyword_score' => $chunk['keyword_score'] ?? 0,
                'rrf_score' => $chunk['rrf_score'] ?? 0,
                'multi_query_bonus' => $chunk['multi_query_bonus'] ?? null,
                'objective' => $objective,
                'source_type' => $chunk['source_type'] ?? 'unknown',
                'payload' => $chunk['payload'] ?? null,
                'priority' => $chunk['priority'] ?? 100,
                'metadata' => $chunk['metadata'] ?? null,
                'source' => $chunk['source'] ?? null,
                'length' => strlen($chunk['text']),
                'embedding' => $chunk['embedding'] ?? null,
            ];

            $state['evidence_by_objective'][$objective][] = $chunk['id'];
        }

        return $state;
    }
    protected function evaluateState(array $state, array $results): array
    {
        $state['confidence'] = $this->computeConfidence($state);

        foreach ($state['objectives'] as $obj) {
            $state['objective_scores'][$obj] = $this->scoreObjective($obj, $state);
        }

        return $state;
    }
    protected function scoreObjective(string $obj, array $state): float
    {
        return $this->hasEvidenceFor($obj, $state)
            ? 1.0
            : 0.0;
    }
    protected function buildFinalContext(array $state): array
    {
        return collect($state['evidence'])
            ->map(function ($e, $i) use ($state) {

                return [
                    'id' => $e['id'] ?? $i,
                    'text' => $e['text'],
                    'objective' => $e['objective'],

                    // 🔥 IMPORTANT pour RetrievalOptimizer
                    'score' => $e['score'] ?? 0,
                    'vector_score' => $e['vector_score'] ?? 0,
                    'keyword_score' => $e['keyword_score'] ?? 0,
                    'rrf_score' => $e['rrf_score'] ?? 0,
                    'multi_query_bonus' => $e['multi_query_bonus'] ?? null,

                    'source_type' => $e['source_type'] ?? 'unknown',
                    'payload' => $e['payload'] ?? null,
                    'priority' => $e['priority'] ?? 100,
                    'metadata' => $e['metadata'] ?? null,
                    'source' => $e['source'] ?? null,
                    'length' => strlen($e['text']),
                    'embedding' => $e['embedding'] ?? null,
                ];
            })
            ->values()
            ->toArray();
    }
    protected function extractKeywords(string $text): array
    {
        $text = strtolower($text);

        $tokens = preg_split('/[\s,.;:!?()]+/', $text);

        return array_values(array_filter($tokens, fn($t) => strlen($t) > 4));
    }
    public function shouldUseMultiHop(QueryPlan $queryPlan): bool
    {
        $this->queryPlan = $queryPlan;
        // 1️⃣ Heuristique rapide
        $heuristic = $this->heuristicDecision();

        if ($heuristic !== null) {
            return $heuristic;
        }

        // 2️⃣ LLM fallback (cas ambigus)
        return $this->llmDecision($queryPlan);
    }
    protected function heuristicDecision(): ?bool
    {
        $intent = $this->queryPlan->intent;

        // 🔥 sûr → multi-hop
        if ($intent === 'comparison') {
            return true;
        }

        // 🔥 sûr → pas multi-hop
        if (in_array($intent, ['pricing', 'navigation', 'lead', 'booking', 'download'])) {
            return false;
        }

        // 🔥 complexité évidente
        /*if (!empty($this->queryPlan->subQueries) && count($this->queryPlan->subQueries) > 1) {
            return true;
        }

        // 🔥 stratégie déjà détectée
        if ($this->queryPlan->searchStrategy === 'decomposition') {
            return true;
        }*/

        // ❓ incertain → laisser LLM décider
        return false;
    }
    protected function llmDecision(): bool
    {
        $prompt = [
            [
                "role" => "system",
                "content" => "Tu es un classificateur. Réponds uniquement par YES ou NO."
            ],
            [
                "role" => "user",
                "content" => "
Question: {$this->queryPlan->cleanQuery}

Doit-on utiliser une recherche multi-hop (plusieurs étapes de recherche) ?

Réponds uniquement:
YES ou NO
"
            ]
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . env('OPENROUTER_API_KEY'),
            ])->post('https://openrouter.ai/api/v1/chat/completions', [
                'model' => 'openai/gpt-4o-mini',
                'messages' => $prompt,
                'temperature' => 0,
                'max_tokens' => 5,
            ]);

            if ($response->successful()) {
                $answer = strtoupper(trim($response->json()['choices'][0]['message']['content'] ?? ''));

                return str_contains($answer, 'YES');
            }

        } catch (\Exception $e) {
            Log::warning("LLM decision failed", ['error' => $e->getMessage()]);
        }

        // 🔥 fallback sécurisé
        return false;
    }
    protected function parseObjective(string $objective): array
    {
        if (str_contains($objective, ':')) {
            return explode(':', $objective, 2);
        }

        return ['generic', $objective];
    }
    protected function isComplete(array $state): bool
    {
        // 🔥 1. tous les objectifs couverts
        $coverageRatio = count($state['covered_objectives']) / max(count($state['objectives']), 1);
        $state['confidence'] = $this->computeConfidence($state);

        if ($coverageRatio < 0.8) {
            return false;
        }

        // 🔥 2. confiance minimale
        if ($state['confidence'] < 0.3) {
            return false;
        }

        // 🔥 3. diversité des sources
        $sourceTypes = collect($state['evidence'])
            ->pluck('source_type')
            ->unique()
            ->count();

        if ($sourceTypes < 1) {
            return false;
        }

        // 🔥 4. éviter arrêt prématuré
        if ($state['hop'] < 1) {
            return false;
        }

        return true;
    }
    protected function hasEvidenceFor(string $objective, array $state): bool
    {
        $evidence = $state['evidence'];
        if (empty($evidence)) return false;

        // 🔥 1. parsing objectif
        [$type, $value] = $this->parseObjective($objective);

        // 🔥 2. embedding objectif
        $objectiveEmbedding = $state['objective_embeddings'][$objective] ?? null;
        if (!$objectiveEmbedding) {
            return false; // sécurité
        }

        // 🔥 3. scorer chaque evidence
        $scores = collect($evidence)->map(function ($e) use ($objectiveEmbedding) {

            if (empty($e['embedding'])) {
                return 0;
            }

            return $this->cosineSimilarity(
                $objectiveEmbedding,
                $e['embedding']
            );
        });

        // 🔥 4. prendre les meilleurs
        $topScores = $scores->sortDesc()->take(3);
        $avgTop = $topScores->avg();

        return $avgTop >= $this->getThresholdForObjective($type);
    }
    protected function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0;
        $normA = 0;
        $normB = 0;

        foreach ($a as $i => $val) {
            $dot += $val * ($b[$i] ?? 0);
            $normA += $val * $val;
            $normB += ($b[$i] ?? 0) * ($b[$i] ?? 0);
        }

        if ($normA == 0 || $normB == 0) return 0;

        return $dot / (sqrt($normA) * sqrt($normB));
    }
    protected function getThresholdForObjective(string $type): float
    {
        return match($type) {
            'constraint' => 0.75,
            'features' => 0.7,
            'comparison' => 0.73,
            default => 0.72,
        };
    }
    protected function ensureObjectiveEmbeddings(array &$state): void
    {
        foreach ($state['objectives'] as $obj) {

            if (!isset($state['objective_embeddings'][$obj])) {

                $state['objective_embeddings'][$obj] =
                    $this->embeddingService->getEmbedding($obj);
            }
        }
    }
    protected function computeConfidence(array $state): float
    {
        $coverage = count($state['covered_objectives']) /
            max(count($state['objectives']), 1);

        $quality = collect($state['evidence'])
            ->avg(fn($e) => $e['score'] ?? 0);

        $diversity = collect($state['evidence'])
                ->pluck('source_type')
                ->unique()
                ->count() / 3;

        return round(
            ($coverage * 0.5) +
            ($quality * 0.3) +
            ($diversity * 0.2),
            3
        );
    }
    protected function getFinalChunks(array $state): array
    {
        return collect($state['evidence'])
            ->groupBy('id')
            ->map(fn($items) => $items->last())
            ->values()
            ->toArray();
    }
}
