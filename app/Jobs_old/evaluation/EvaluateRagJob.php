<?php

namespace App\Jobs\evaluation;

use App\Models\Chunk;
use App\Models\RagEvaluationQuery;
use App\Models\RagEvaluationResult;
use App\Models\RagEvaluationRun;
use App\Services\evaluation\EvaluationProgressTracker;
use App\Services\evaluation\QueryGenerationService;
use App\Services\evaluation\RagEvaluationPipeline;
use App\Services\evaluation\RagRecommendationService;
use App\Services\MercureService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class EvaluateRagJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $runId
    ) {}
    /**
     * Execute the job.
     */
    public function handle(
        RagEvaluationPipeline $pipeline,
        QueryGenerationService $queryGenerator,
        MercureService $mercureService,
        RagRecommendationService $recommendationService
    ): void {

        $run = RagEvaluationRun::findOrFail($this->runId);

        // =====================================================
        // START
        // =====================================================

        $this->notify(
            mercure: $mercureService,
            siteId: $run->site_id,
            message: "Préparation de l'évaluation IA...",
            progress: 2
        );

        // =====================================================
        // LOAD / GENERATE QUERIES
        // =====================================================

        $queries = RagEvaluationQuery::where(
            'run_id',
            $run->id
        )->get();

        // 🔥 génération automatique si aucune question
        if ($queries->isEmpty()) {

            $this->notify(
                mercure: $mercureService,
                siteId: $run->site_id,
                message: "Analyse de la base de connaissances...",
                progress: 5
            );

            try {

                $chunks = $this->selectEvaluationChunks($run->site_id);

                $generatedQueries = $queryGenerator->generate(
                    siteId: $run->site_id,
                    chunks: $chunks,
                    target: 8
                );

                foreach ($generatedQueries as $generated) {

                    RagEvaluationQuery::create([
                        'site_id' => $run->site_id,

                        'run_id' => $run->id,

                        'query' => $generated['query'],

                        // JSON column
                        'expected_chunk_ids' =>
                            $generated['expected_chunk_ids'] ?? [],

                        // correspond à "intent"
                        'category' =>
                            $generated['intent'] ?? 'informational',

                        // conversion propre vers int
                        'difficulty' => match (
                        strtolower($generated['difficulty'] ?? 'medium')
                        ) {
                            'easy' => 1,
                            'medium' => 2,
                            'hard' => 3,
                            default => 2,
                        },
                    ]);
                }

                $queries = RagEvaluationQuery::where(
                    'run_id',
                    $run->id
                )->get();

                $this->notify(
                    mercure: $mercureService,
                    siteId: $run->site_id,
                    message: "Questions générées avec succès",
                    progress: 18
                );

            } catch (Throwable $e) {

                Log::error('RAG query generation failed', [
                    'site_id' => $run->site_id,
                    'error' => $e->getMessage(),
                ]);

                $this->notify(
                    mercure: $mercureService,
                    siteId: $run->site_id,
                    message: "Erreur lors de la génération des questions",
                    progress: 0,
                    done: true,
                    type: 'indexing_error'
                );

                $run->status = 'failed';
                $run->save();

                return;
            }
        }

        // =====================================================
        // EVALUATION LOOP
        // =====================================================

        $results = [];

        $total = max($queries->count(), 1);

        foreach ($queries as $index => $q) {

            $current = $index + 1;

            $progress = 20 + intval(($current / $total) * 60);

            $this->notify(
                mercure: $mercureService,
                siteId: $run->site_id,
                message: "Évaluation IA {$current}/{$total}...",
                progress: $progress
            );

            try {

                $result = $pipeline->evaluate(
                    siteId: $run->site_id,
                    query: $q->query,
                    expectedChunkIds: $q->expected_chunk_ids ?? []
                );

                // =====================================================
                // SAVE RESULT
                // =====================================================

                RagEvaluationResult::create([

                    'run_id' => $run->id,
                    'site_id' => $run->site_id,

                    'query' => $q->query,

                    // =========================================
                    // TRACE / RETRIEVAL
                    // =========================================

                    'retrieved_chunks' =>
                        $result['trace']['retrieval_raw'] ?? [],

                    'vector_results' =>
                        $result['trace']['vector_results'] ?? [],

                    'keyword_results' =>
                        $result['trace']['keyword_results'] ?? [],

                    'hybrid_results' =>
                        $result['trace']['hybrid_results'] ?? [],

                    'reranked_chunks' =>
                        $result['trace']['reranked'] ?? [],

                    // =========================================
                    // ANSWER
                    // =========================================

                    'llm_answer' =>
                        $result['answer'] ?? null,

                    // =========================================
                    // RETRIEVAL METRICS
                    // =========================================

                    'retrieval_recall' =>
                        $result['metrics']['retrieval']['recall'] ?? 0,

                    'mrr' =>
                        $result['metrics']['retrieval']['mrr'] ?? 0,

                    'ndcg' =>
                        $result['metrics']['retrieval']['ndcg'] ?? 0,

                    // =========================================
                    // GENERATION METRICS
                    // =========================================

                    'faithfulness' =>
                        $result['metrics']['generation']['faithfulness'] ?? 0,

                    'groundedness' =>
                        $result['metrics']['generation']['groundedness'] ?? 0,

                    'answer_relevance' =>
                        $result['metrics']['generation']['relevance'] ?? 0,
                ]);

                $results[] = $result;

            } catch (Throwable $e) {

                Log::error('RAG evaluation query failed', [
                    'site_id' => $run->site_id,
                    'query' => $q->query,
                    'error' => $e->getMessage(),
                ]);

                $this->notify(
                    mercure: $mercureService,
                    siteId: $run->site_id,
                    message: "Erreur durant l'évaluation d'une requête",
                    progress: $progress,
                    done: false,
                    type: 'indexing_warning'
                );
            }
        }

        // =====================================================
        // AGGREGATION
        // =====================================================

        $this->notify(
            mercure: $mercureService,
            siteId: $run->site_id,
            message: "Agrégation des métriques et analyse qualité...",
            progress: 88
        );

        $this->aggregateRun($run, $results);

        $this->notify(
            mercure: $mercureService,
            siteId: $run->site_id,
            message: "Génération des recommandations d'amélioration...",
            progress: 94
        );

        $run->recommendations =
            $recommendationService->generate(
                run: $run,
                results: $results
            );

        $run->save();

        // =====================================================
        // FINISH
        // =====================================================

        $this->notify(
            mercure: $mercureService,
            siteId: $run->site_id,
            message: "Finalisation du rapport d'évaluation...",
            progress: 99
        );

        $run->status = 'completed';
        $run->save();

        $this->notify(
            mercure: $mercureService,
            siteId: $run->site_id,
            message: "Évaluation IA terminée",
            progress: 100,
            done: true
        );
    }
    /**
     * Aggregate final metrics.
     */
    /*private function aggregateRun(
        RagEvaluationRun $run,
        array $results
    ): void {

        $run->retrieval_score = round(
            collect($results)->avg('metrics.retrieval.recall') ?? 0,
            4
        );

        $run->answer_quality_score = round(
            collect($results)->avg('metrics.generation.relevance') ?? 0,
            4
        );

        $run->groundedness_score = round(
            collect($results)->avg('metrics.generation.groundedness') ?? 0,
            4
        );

        $run->metrics_breakdown = [

            'avg_mrr' => round(
                collect($results)->avg('metrics.retrieval.mrr') ?? 0,
                4
            ),

            'avg_ndcg' => round(
                collect($results)->avg('metrics.retrieval.ndcg') ?? 0,
                4
            ),

            'hallucination_rate' => round(
                collect($results)->avg('metrics.generation.hallucination') ?? 0,
                4
            ),

            'avg_faithfulness' => round(
                collect($results)->avg('metrics.generation.faithfulness') ?? 0,
                4
            ),

            'avg_groundedness' => round(
                collect($results)->avg('metrics.generation.groundedness') ?? 0,
                4
            ),

            'avg_relevance' => round(
                collect($results)->avg('metrics.generation.relevance') ?? 0,
                4
            ),

            'final_score' => round(
                collect($results)
                    ->avg('final_score.final_score') ?? 0,
                4
            ),
        ];

        $run->save();
    }*/
    /*private function aggregateRun(
        RagEvaluationRun $run,
        array $results
    ): void {

        $collection = collect($results);

        // =====================================================
        // 1. RETRIEVAL
        // =====================================================

        $retrieval = $collection->avg('metrics.retrieval.recall') ?? 0;

        $mrr = $collection->avg('metrics.retrieval.mrr') ?? 0;
        $ndcg = $collection->avg('metrics.retrieval.ndcg') ?? 0;

        $ranking = ($mrr * 0.5) + ($ndcg * 0.5);

        // =====================================================
        // 2. GENERATION
        // =====================================================

        $faithfulness = $collection->avg('metrics.generation.faithfulness') ?? 0;
        $groundedness = $collection->avg('metrics.generation.groundedness') ?? 0;
        $relevance = $collection->avg('metrics.generation.relevance') ?? 0;
        $hallucination = $collection->avg('metrics.generation.hallucination') ?? 0;

        $generation =
            ($faithfulness * 0.4) +
            ($groundedness * 0.3) +
            ($relevance * 0.3);

        // =====================================================
        // 3. OVERALL SCORE (BUSINESS KPI)
        // =====================================================

        $overall =
            ($retrieval * 0.4) +
            ($ranking * 0.2) +
            ($generation * 0.4);

        // =====================================================
        // 4. WRITE CORE SCORES
        // =====================================================

        $run->retrieval_score = round($retrieval, 4);
        $run->groundedness_score = round($groundedness, 4);
        $run->answer_quality_score = round($relevance, 4);
        $run->hallucination_rate = round($hallucination, 4);

        // 👉 nouveaux champs (si ajoutés migration)
        if (isset($run->ranking_score)) {
            $run->ranking_score = round($ranking, 4);
        }

        if (isset($run->generation_score)) {
            $run->generation_score = round($generation, 4);
        }

        if (isset($run->overall_score)) {
            $run->overall_score = round($overall, 4);
        }

        // =====================================================
        // 5. METRICS BREAKDOWN (AUDIT / DEBUG)
        // =====================================================

        $run->metrics_breakdown = [

            // retrieval
            'retrieval' => [
                'recall' => round($retrieval, 4),
            ],

            // ranking
            'ranking' => [
                'mrr' => round($mrr, 4),
                'ndcg' => round($ndcg, 4),
                'ranking_score' => round($ranking, 4),
            ],

            // generation
            'generation' => [
                'faithfulness' => round($faithfulness, 4),
                'groundedness' => round($groundedness, 4),
                'relevance' => round($relevance, 4),
                'hallucination' => round($hallucination, 4),
                'generation_score' => round($generation, 4),
            ],

            // final
            'final' => [
                'overall_score' => round($overall, 4),
            ],
        ];

        $run->save();
    }*/
    private function aggregateRun(
        RagEvaluationRun $run,
        array $results
    ): void {
        $collection = collect($results);

        if ($collection->isEmpty()) {
            $run->metrics_breakdown = [
                'error' => 'no_results'
            ];
            return;
        }

        $run->total_queries = $collection->count();

        // =====================================================
        // SAFE AVG HELPER
        // =====================================================
        $avg = fn(string $key) =>
        (float) ($collection->avg($key) ?? 0);

        // =====================================================
        // 1. RETRIEVAL
        // =====================================================
        $retrieval = $avg('metrics.retrieval.recall');

        $mrr = $avg('metrics.retrieval.mrr');
        $ndcg = $avg('metrics.retrieval.ndcg');

        // normalisation robuste (déjà 0-1 mais sécurise)
        $mrr = min(max($mrr, 0), 1);
        $ndcg = min(max($ndcg, 0), 1);

        $ranking = ($mrr * 0.5) + ($ndcg * 0.5);

        // =====================================================
        // 2. GENERATION
        // =====================================================
        $faithfulness = $avg('metrics.generation.faithfulness');
        $groundedness = $avg('metrics.generation.groundedness');
        $relevance = $avg('metrics.generation.relevance');
        $hallucination = $avg('metrics.safety.hallucination');

        // clamp sécurité
        $faithfulness = min(max($faithfulness, 0), 1);
        $groundedness = min(max($groundedness, 0), 1);
        $relevance = min(max($relevance, 0), 1);
        $hallucination = min(max($hallucination, 0), 1);

        // hallucination doit pénaliser le score
        $hallucinationPenalty = 1 - $hallucination;

        $generation =
            ($faithfulness * 0.35) +
            ($groundedness * 0.25) +
            ($relevance * 0.25) +
            ($hallucinationPenalty * 0.15);

        // =====================================================
        // 3. OVERALL SCORE (BUSINESS KPI)
        // =====================================================
        $overall =
            ($retrieval * 0.35) +
            ($ranking * 0.25) +
            ($generation * 0.40);

        $overall = min(max($overall, 0), 1);

        // =====================================================
        // 4. WRITE CORE SCORES
        // =====================================================
        $run->retrieval_score = round($retrieval, 4);
        $run->groundedness_score = round($groundedness, 4);
        $run->answer_quality_score = round($relevance, 4);
        $run->hallucination_rate = round($hallucination, 4);

        $run->ranking_score = round($ranking, 4);
        $run->generation_score = round($generation, 4);
        $run->overall_score = round($overall, 4);

        // =====================================================
        // 5. AUDITABLE BREAKDOWN (IMPORTANT JURIDIQUE)
        // =====================================================
        $run->metrics_breakdown = [
            'retrieval' => [
                'recall' => round($retrieval, 4),
            ],

            'ranking' => [
                'mrr' => round($mrr, 4),
                'ndcg' => round($ndcg, 4),
                'ranking_score' => round($ranking, 4),
            ],

            'generation' => [
                'faithfulness' => round($faithfulness, 4),
                'groundedness' => round($groundedness, 4),
                'relevance' => round($relevance, 4),
                'hallucination' => round($hallucination, 4),
                'generation_score' => round($generation, 4),
            ],

            'final' => [
                'overall_score' => round($overall, 4),
            ],

            // bonus: utile pour debugging futur
            'stats' => [
                'queries_count' => $collection->count(),
            ],
        ];

        $run->metrics_administrator = [

            // =========================================
            // SCORE GLOBAL BUSINESS
            // =========================================

            'assistant_quality_score' => round($overall, 4),

            // =========================================
            // EXPERIENCE UTILISATEUR
            // =========================================

            'response_relevance' => round($relevance, 4),

            'response_reliability' => round(
                (
                    ($faithfulness * 0.5)
                    + ($groundedness * 0.5)
                ),
                4
            ),

            // =========================================
            // COUVERTURE DES QUESTIONS
            // =========================================

            'knowledge_coverage' => round($retrieval, 4),

            // =========================================
            // RISQUE UTILISATEUR
            // =========================================

            'incorrect_answer_risk' => round($hallucination, 4),

            // =========================================
            // QUALITÉ GLOBALE
            // =========================================

            'performance_level' => match (true) {

                $overall >= 0.85 => 'excellent',

                $overall >= 0.70 => 'good',

                $overall >= 0.50 => 'average',

                default => 'poor',
            },

            // =========================================
            // META
            // =========================================

            'evaluated_questions' => $collection->count(),
        ];

        $run->save();
    }
    /**
     * Mercure realtime notification helper.
     */
    private function notify(
        MercureService $mercure,
        string $siteId,
        string $message,
        int $progress,
        bool $done = false,
        string $type = 'indexing_progress'
    ): void {

        $mercure->post(
            "site/{$siteId}/knowledge/indexing",
            [
                'type' => $type,
                'progress' => $progress,
                'message' => $message,
                'done' => $done,
            ]
        );
    }
    /**
     * Global failure handler.
     */
    public function failed(Throwable $e): void
    {
        Log::error('EvaluateRagJob failed', [
            'run_id' => $this->runId,
            'error' => $e->getMessage(),
        ]);

        $run = RagEvaluationRun::find($this->runId);

        if (!$run) {
            return;
        }

        $run->status = 'failed';
        $run->save();

        app(MercureService::class)->post(
            "site/{$run->site_id}/knowledge/indexing",
            [
                'type' => 'indexing_error',
                'progress' => 0,
                'message' => "Erreur durant l'évaluation IA",
                'done' => true,
            ]
        );
    }
    private function selectEvaluationChunks(string $siteId): array
    {
        // =====================================================
        // 1️⃣ Base Query
        // =====================================================

        $chunks = Chunk::query()
            ->where('site_id', $siteId)

            // IMPORTANT
            ->whereNotNull('text')

            // évite bruit extrême
            ->whereRaw('LENGTH(text) > 120')

            // exclure alias pauvres
            ->where(function ($q) {
                $q->whereNull('metadata->type')
                    ->orWhereNotIn('metadata->type', [
                        'statistical_alias'
                    ]);
            })

            // seuil qualité minimal
            ->where('priority', '>=', 45)

            ->orderByDesc('priority')
            ->get();

        if ($chunks->isEmpty()) {
            return [];
        }

        // =====================================================
        // 2️⃣ Diversification intelligente
        // =====================================================

        $grouped = $chunks->groupBy(function ($chunk) {

            if ($chunk->document_id) {
                return 'doc_' . $chunk->document_id;
            }

            if ($chunk->page_id) {
                return 'page_' . $chunk->page_id;
            }

            if ($chunk->product_id) {
                return 'product_' . $chunk->product_id;
            }

            return 'other';
        });

        $selected = collect();

        // équilibrage par source
        foreach ($grouped as $items) {

            $selected = $selected->merge(
                $items
                    ->sortByDesc('priority')
                    ->take(5)
            );
        }

        // =====================================================
        // 3️⃣ Déduplication textuelle
        // =====================================================

        $selected = $selected
            ->unique(function ($chunk) {
                return md5(
                    mb_substr(
                        strtolower(trim($chunk->text)),
                        0,
                        500
                    )
                );
            })
            ->values();

        // =====================================================
        // 4️⃣ Stable vs Random Split
        // =====================================================

        $maxChunks = 80;

        $stableCount = (int) round($maxChunks * 0.7); // 56
        $randomCount = $maxChunks - $stableCount; // 24

        // -----------------------------------------------------
        // Stable curated chunks
        // -----------------------------------------------------

        $stable = $selected
            ->sortByDesc('priority')
            ->take($stableCount);

        // -----------------------------------------------------
        // Exploration pool
        // IMPORTANT:
        // uniquement qualité moyenne+
        // -----------------------------------------------------

        $remaining = $selected
            ->reject(fn ($chunk) =>
            $stable->contains('id', $chunk->id)
            )
            ->filter(fn ($chunk) =>
                $chunk->priority >= 45
                && $chunk->priority < 75
            )
            ->values();

        // -----------------------------------------------------
        // Randomized coverage
        // -----------------------------------------------------

        $random = $remaining
            ->shuffle()
            ->take($randomCount);

        // =====================================================
        // 5️⃣ Final Merge
        // =====================================================

        return $stable
            ->merge($random)

            // IMPORTANT:
            // évite biais début/fin contexte LLM
            ->shuffle()

            ->values()
            ->toArray();
    }
}
