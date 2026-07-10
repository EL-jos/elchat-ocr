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
use App\Services\ia\ConversationRewriterService;
use App\Services\ia\EmbeddingService;
use App\Services\ia\EntityExtractor;
use App\Services\ia\EntityRelevanceService;
use App\Services\ia\EntityResolver;
use App\Services\ia\FollowUpDetector;
use App\Services\ia\PromptBuilder;
use App\Services\queryAnalyzer\LeadService;
use App\Services\queryAnalyzer\NavigationService;
use App\Services\queryAnalyzer\QueryPlan;
use App\Services\queryAnalyzer\TransactionService;
use App\Services\rag\ContextCompressor;
use App\Services\rag\ContextSelectionService;
use App\Services\rag\ContextValidator;
use App\Services\rag\LLMReRankerService;
use App\Services\rag\RetrievalOptimizer;
use App\Services\vector\VectorSearchService;
use App\Traits\TextNormalizer;
use Illuminate\Support\Facades\Log;

class SingleHopPipelineService

{

    use TextNormalizer;

    protected array $handlers = [

        'lead_capture' => LeadService::class,
        'navigation' => NavigationService::class,
        'transaction_flow' => TransactionService::class,
    ];

    private array $entityLabels = [
        'product' => [
            'singular' => 'produit',
            'plural' => 'produits',
            'priority' => 1,
        ],
        'page' => [
            'singular' => 'page',
            'plural' => 'pages',
            'priority' => 2,
        ],
        'document' => [
            'singular' => 'document',
            'plural' => 'documents',
            'priority' => 3,
        ],
        'image' => [
            'singular' => 'image',
            'plural' => 'images',
            'priority' => 4,
        ],
    ];

    public function __construct(
        protected EmbeddingService $embeddingService,
        protected PromptBuilder $promptBuilder,
        protected VectorSearchService $vectorSearchService,
        protected ChunkHydrationService $chunkHydrationService,
        protected ChunkRankingService $chunkRankingService,
        protected ContextBuilder $contextBuilder,
        protected FollowUpDetector $followUpDetector,
        protected ConversationRewriterService $rewriter,
        protected EntityResolver $entityResolver,
        protected RetrievalOptimizer $retrievalOptimizer,
        protected ContextValidator $contextValidator,
        protected ContextCompressor $contextCompressor,
        protected CTAEngine $CTAEngine,
        protected EntityExtractor $entityExtractor,
        protected EntityRelevanceService $entityRelevanceService,
        protected CTARelevanceService $ctaRelevanceService,
        protected HybridSearchService $hybridSearchService,
        protected LLMReRankerService $LLMReRankerService,
        protected ContextSelectionService $contextSelectionService,

    )
    {}

    /**
     * Réponse commerciale incarnée (mode production)
     */
    public function handle(string $question, QueryPlan $queryPlan, Site $site, Conversation $conversation = null, array $history = [],): HopResponse
    {

        $query = $queryPlan->cleanQuery;
        /*Log::info("QueryPlan", [
            "clean_query" => $queryPlan->cleanQuery,
            "strategy" => $queryPlan->searchStrategy,
            "queries" => $queryPlan->searchQueries,
            "sub_queries" => $queryPlan->subQueries,
            "top_k" => $queryPlan->topK
        ]);*/
        $queries = null;
        switch ($queryPlan->searchStrategy) {
            case 'decomposition':
                $queries = $queryPlan->subQueries ?: [$queryPlan->cleanQuery];
                break;
            case 'multi_query':
                $queries = $queryPlan->searchQueries ?: [$queryPlan->cleanQuery];
                break;
            default:
                $queries = [$queryPlan->cleanQuery];
        }
        /*Log::info("Queries", [
            "queries" => $queries,
        ]);*/

        // ─────────────────────────────
        // 2️⃣ Retrieval (Single ou Multi-hop)
        // ─────────────────────────────

        $resultsMap = [];

        foreach ($queries as $q) {

            $embedding = $this->embeddingService->getEmbedding($q);

            $partial = $this->hybridSearchService->search(
                query: $q,
                embedding: $embedding,
                siteId: $site->id,
                limit: $queryPlan->topK ?? 30,
                scoreThreshold: floatval($site->settings->min_similarity_score),
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
            ->sortByDesc('score')
            ->values()
            ->toArray();
        //Log::info("APRES RECHERCHE HYBRIDE APRES CLASSEMENT", $resultsHybridSearch);

        // ─────────────────────────────
        // 5️⃣ Hydratation
        // ─────────────────────────────
        $hydrated = $this->chunkHydrationService->hydrate($resultsHybridSearch);
        //Log::info("Hydrated Chunks :", $hydrated);
        $historyMessagesResults = [];
        $hydratedMessages = $this->chunkHydrationService->hydrateMessages($historyMessagesResults);
        //Log::info("Hydrated Messages :", $hydratedMessages);

        $resultsOptimizer = $this->retrievalOptimizer->optimize(
            $hydrated,
            $queryPlan
        );
        //Log::info("Optimized Results", $resultsOptimizer);

        $resultsReRanker = $this->LLMReRankerService->rerank(
            query: $query,
            chunks: $resultsOptimizer,
            topK: 12
        );
        //Log::info("ReRanking Results", $resultsReRanker);

        // 3️⃣ Fallback si rien trouvé
        if (empty($resultsReRanker)) {
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

        // ─────────────────────────────
        // 6️⃣ Ranking métier
        // ─────────────────────────────
        $ragContextChunks = $this->chunkRankingService->rank($resultsReRanker, floatval($site->settings->min_similarity_score));
        //Log::info("RAG CONTEXT CHUNKS", $ragContextChunks);

        $resultsContextSelection = $this->contextSelectionService->select(
            $ragContextChunks,
            $queryPlan,
            10
        );
        //Log::info("CHUNKS SELECT", $resultsContextSelection);

        $ragContextChunks = $this->entityResolver->resolve(collect($resultsContextSelection));
        $entities = $this->entityExtractor->extract($ragContextChunks);
        //Log::info("ENTITIES RECUPERER: ",  $entities);
        $ragContextMessages = collect($hydratedMessages)->sortByDesc('vector_score')->take(5)->toArray();

        // Après avoir hydraté et résolu les entités
        $ragContextChunks = collect($ragContextChunks)
            ->map(fn($chunk) => [
                ...$chunk,
                'text' => $chunk['text'],
            ])->toArray();
        $ragContextMessages = collect($ragContextMessages)
            ->map(fn($msg) => [
                ...$msg,
                'text' => $msg['text'],
            ])->toArray();


        // ─────────────────────────────
        // 7️⃣ Fusion + limite globale
        // ─────────────────────────────
        $allContextChunks = collect(array_merge($ragContextChunks, $ragContextMessages))
            ->sortByDesc(function ($c) {
                $score = $c['final_score'] ?? $c['vector_score'] ?? $c['score'] ?? 0;

                // 🔥 pénaliser messages légèrement
                if (($c['type'] ?? null) === 'message') {
                    $score *= 0.8;
                }

                // 🔥 pénaliser alias fort
                if (($c['type'] ?? null) === 'statistical_alias') {
                    $score *= 0.6;
                }

                return $score;
            })
            ->toArray();
        $maxChunks = 10; // chunks + messages
        $allContextChunks = array_slice($allContextChunks, 0, $maxChunks);
        // Construire le contexte final pour le LLM
        $context = $this->contextBuilder->build($allContextChunks);
        /*Log::info("CONTEXT BUILDER", [
            'context' => $context
        ]);*/

        if (trim($context) === '') {
            return new HopResponse(
                message: "Je n’ai pas d’information fiable à ce sujet pour le moment.",
                ctas: [],
                entities: []
            );
        }

        /*$entities = $this->entityRelevanceService->filterRelevant(
            $entities,
            $query,
            $queryPlan->entities ?? []
        );*/
        $ctas = $this->CTAEngine->resolve($site, $queryPlan, $conversation);
        //Log::info("Resolved CTAs:", ['ctas' => $ctas]);
        /*$ctas = $this->ctaRelevanceService->filterRelevant(
            $ctas,
            $queryPlan,
            $query,
            $entities
        );*/

        // ─────────────────────────────
        // 8️⃣ Construction Prompt
        // ─────────────────────────────

        $promptPayload = $this->promptBuilder->build(
            site: $site,
            question: $question,
            context: $context,
            history: $history,
            conversation: $conversation,
            cats: $ctas,
            entities: $entities
        );

        //Log::info("Prompt Payload:", $promptPayload);

        return new HopResponse(
            prompt: $promptPayload,
            ctas: $ctas,
            entities: $entities,
            context: $context
        );

    }
}
