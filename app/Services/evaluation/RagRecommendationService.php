<?php

namespace App\Services\evaluation;

use App\Models\RagEvaluationRun;
use App\Services\hops\LLMService;

class RagRecommendationService
{
    public function __construct(
        protected LLMService $llm
    ) {}

    /**
     * =========================================================
     * MAIN ENTRY
     * =========================================================
     */
    public function generate(
        RagEvaluationRun $run,
        array $results
    ): array {

        $metrics = $run->metrics_breakdown ?? [];

        // =====================================================
        // 1️⃣ DETERMINISTIC ANALYSIS
        // =====================================================

        $deterministic = $this->buildDeterministicRecommendations(
            metrics: $metrics,
            results: $results
        );

        // =====================================================
        // 2️⃣ LLM INSIGHTS (SAFE / OPTIONAL)
        // =====================================================

        $llmInsights = $this->buildLLMInsights(
            metrics: $metrics,
            results: $results
        );

        // =====================================================
        // 3️⃣ PRIORITIZATION
        // =====================================================

        $prioritized = collect([
            ...$deterministic,
            ...$llmInsights,
        ])
            ->sortByDesc('priority')
            ->values()
            ->toArray();

        return [
            'summary' => $this->buildExecutiveSummary(
                metrics: $metrics,
                recommendations: $prioritized
            ),
            'recommendations' => $prioritized,
            /*
           |--------------------------------------------------------------------------
           | INTERNAL (DEV)
           |--------------------------------------------------------------------------
           */
            'risk_level' => $this->computeRiskLevel($metrics),
            'retrieval_health' => $this->computeRetrievalHealth($metrics),
            'generation_health' => $this->computeGenerationHealth($metrics),
            /*
            |--------------------------------------------------------------------------
            | ADMIN SAFE
            |--------------------------------------------------------------------------
            */
            'administrator' => [
                'scores' => $this->buildAdministratorScores($metrics),
                'recommendations' => $this->buildAdministratorRecommendations(
                    $metrics,
                    $prioritized
                ),
                'global_status' => $this->buildAdministratorStatus($metrics),
            ],
        ];
    }

    /**
     * =========================================================
     * DETERMINISTIC RULES
     * =========================================================
     */
    private function buildDeterministicRecommendations(
        array $metrics,
        array $results
    ): array {

        $recommendations = [];

        $recall = $metrics['retrieval_score']
            ?? $metrics['avg_recall']
            ?? 0;

        $mrr = $metrics['avg_mrr'] ?? 0;

        $ndcg = $metrics['avg_ndcg'] ?? 0;

        $hallucination = $metrics['hallucination_rate'] ?? 0;

        $faithfulness = $metrics['avg_faithfulness'] ?? 0;

        $groundedness = $metrics['avg_groundedness'] ?? 0;

        $relevance = $metrics['avg_relevance'] ?? 0;

        // =====================================================
        // RETRIEVAL ISSUES
        // =====================================================

        if ($recall < 0.55) {

            $recommendations[] = [
                'type' => 'retrieval',
                'severity' => 'high',
                'priority' => 95,

                'title' =>
                    'Faible couverture retrieval',

                'description' =>
                    'Le moteur RAG ne retrouve pas suffisamment les chunks attendus.',

                'impact' =>
                    'Les utilisateurs risquent de recevoir des réponses incomplètes ou incorrectes.',

                'actions' => [
                    'Ajouter davantage de contenu descriptif',
                    'Améliorer la qualité du chunking',
                    'Augmenter le recouvrement sémantique',
                    'Ajouter des synonymes et formulations naturelles',
                ],

                'evidence' => [
                    'recall' => round($recall, 4),
                ],
            ];
        }

        // =====================================================
        // RANKING ISSUES
        // =====================================================

        if ($mrr < 0.45 && $recall >= 0.55) {

            $recommendations[] = [
                'type' => 'ranking',
                'severity' => 'medium',
                'priority' => 80,

                'title' =>
                    'Ranking retrieval sous-optimal',

                'description' =>
                    'Les bons chunks existent mais apparaissent trop bas dans les résultats.',

                'impact' =>
                    'Le LLM peut utiliser des contextes moins pertinents.',

                'actions' => [
                    'Améliorer le reranking',
                    'Ajuster les poids hybride BM25/vectoriel',
                    'Réduire le bruit documentaire',
                ],

                'evidence' => [
                    'mrr' => round($mrr, 4),
                ],
            ];
        }

        // =====================================================
        // SEMANTIC ORDERING
        // =====================================================

        if ($ndcg < 0.60) {

            $recommendations[] = [
                'type' => 'ranking',
                'severity' => 'medium',
                'priority' => 70,

                'title' =>
                    'Ordonnancement sémantique perfectible',

                'description' =>
                    'Les chunks pertinents ne sont pas suffisamment priorisés.',

                'impact' =>
                    'La qualité contextuelle diminue malgré un bon retrieval.',

                'actions' => [
                    'Optimiser le reranker',
                    'Réduire les chunks trop génériques',
                    'Améliorer les embeddings',
                ],

                'evidence' => [
                    'ndcg' => round($ndcg, 4),
                ],
            ];
        }

        // =====================================================
        // HALLUCINATION
        // =====================================================

        if ($hallucination > 0.35) {

            $recommendations[] = [
                'type' => 'generation',
                'severity' => 'critical',
                'priority' => 100,

                'title' =>
                    'Risque élevé d’hallucination',

                'description' =>
                    'Le modèle génère des informations insuffisamment supportées par le contexte.',

                'impact' =>
                    'Le chatbot peut produire des réponses juridiquement ou commercialement risquées.',

                'actions' => [
                    'Réduire la température du modèle',
                    'Renforcer les garde-fous de génération',
                    'Améliorer le grounding retrieval',
                    'Ajouter davantage de contenu factuel',
                ],

                'evidence' => [
                    'hallucination_rate' => round($hallucination, 4),
                ],
            ];
        }

        // =====================================================
        // FAITHFULNESS
        // =====================================================

        if ($faithfulness < 0.60) {

            $recommendations[] = [
                'type' => 'generation',
                'severity' => 'high',
                'priority' => 90,

                'title' =>
                    'Faible fidélité au contexte',

                'description' =>
                    'Les réponses ne sont pas suffisamment basées sur les documents internes.',

                'impact' =>
                    'Le système peut extrapoler ou reformuler incorrectement.',

                'actions' => [
                    'Renforcer les prompts système',
                    'Limiter la créativité du modèle',
                    'Réduire le nombre de chunks non pertinents',
                ],

                'evidence' => [
                    'faithfulness' => round($faithfulness, 4),
                ],
            ];
        }

        // =====================================================
        // GROUNDEDNESS
        // =====================================================

        if ($groundedness < 0.60) {

            $recommendations[] = [
                'type' => 'grounding',
                'severity' => 'high',
                'priority' => 88,

                'title' =>
                    'Grounding insuffisant',

                'description' =>
                    'Les réponses ne sont pas correctement ancrées dans les passages retrouvés.',

                'impact' =>
                    'Le modèle peut produire des réponses plausibles mais non vérifiables.',

                'actions' => [
                    'Réduire le bruit retrieval',
                    'Améliorer la précision des chunks',
                    'Augmenter la qualité documentaire',
                ],

                'evidence' => [
                    'groundedness' => round($groundedness, 4),
                ],
            ];
        }

        // =====================================================
        // RELEVANCE
        // =====================================================

        if ($relevance < 0.65) {

            $recommendations[] = [
                'type' => 'relevance',
                'severity' => 'medium',
                'priority' => 75,

                'title' =>
                    'Réponses insuffisamment pertinentes',

                'description' =>
                    'Le chatbot répond partiellement ou incorrectement à certaines questions.',

                'impact' =>
                    'Expérience utilisateur dégradée.',

                'actions' => [
                    'Ajouter des FAQ ciblées',
                    'Améliorer les documents métier',
                    'Créer des contenus orientés intentions utilisateur',
                ],

                'evidence' => [
                    'relevance' => round($relevance, 4),
                ],
            ];
        }

        // =====================================================
        // HARD QUERIES ANALYSIS
        // =====================================================

        $hardFailures = collect($results)
            ->filter(function ($r) {

                $relevance =
                    $r['metrics']['generation']['relevance'] ?? 0;

                return $relevance < 0.5;
            })
            ->count();

        if ($hardFailures >= 3) {

            $recommendations[] = [
                'type' => 'multi_hop',
                'severity' => 'medium',
                'priority' => 72,

                'title' =>
                    'Difficulté sur les requêtes complexes',

                'description' =>
                    'Le système peine à combiner plusieurs informations dispersées.',

                'impact' =>
                    'Les questions complexes ou comparatives peuvent échouer.',

                'actions' => [
                    'Ajouter des documents structurés',
                    'Créer des FAQ comparatives',
                    'Améliorer les relations inter-contenus',
                ],

                'evidence' => [
                    'failed_complex_queries' => $hardFailures,
                ],
            ];
        }

        return $recommendations;
    }

    /**
     * =========================================================
     * LLM INSIGHTS
     * =========================================================
     */
    private function buildLLMInsights(
        array $metrics,
        array $results
    ): array {

        try {

            $sampleFailures = collect($results)
                ->filter(function ($r) {

                    return (
                        ($r['metrics']['generation']['relevance'] ?? 0) < 0.6
                        || ($r['metrics']['generation']['hallucination'] ?? 0) > 0.4
                    );
                })
                ->take(5)
                ->map(function ($r) {

                    return [
                        'query' => $r['query'] ?? null,
                        'answer' => mb_substr(
                            $r['answer'] ?? '',
                            0,
                            400
                        ),
                    ];
                })
                ->values()
                ->toArray();

            if (empty($sampleFailures)) {
                return [];
            }

            $response = $this->llm->chatJson([
                [
                    'role' => 'system',
                    'content' =>
                        "Tu es un auditeur expert de systèmes RAG.

Tu dois produire des recommandations professionnelles,
courtes, concrètes et actionnables.

IMPORTANT :
- Ne jamais inventer de métriques
- Ne jamais exagérer les risques
- Être factuel
- Maximum 3 recommandations
- Réponse JSON uniquement"
                ],
                [
                    'role' => 'user',
                    'content' => json_encode([
                        'metrics' => $metrics,
                        'failing_examples' => $sampleFailures,

                        'output_format' => [
                            'recommendations' => [
                                [
                                    'title' => 'string',
                                    'description' => 'string',
                                    'action' => 'string',
                                    'priority' => 'low|medium|high'
                                ]
                            ]
                        ]
                    ])
                ]
            ], [
                'task' => 'rag_recommendation',
                'temperature' => 0.1,
                'max_tokens' => 700,
            ]);

            return collect(
                $response['recommendations'] ?? []
            )
                ->map(function ($r) {

                    return [
                        'type' => 'ai_insight',

                        'severity' =>
                            $r['priority'] ?? 'medium',

                        'priority' => match (
                        strtolower($r['priority'] ?? 'medium')
                        ) {
                            'high' => 78,
                            'medium' => 60,
                            default => 40,
                        },

                        'title' =>
                            $r['title'] ?? 'AI Insight',

                        'description' =>
                            $r['description'] ?? null,

                        'impact' =>
                            null,

                        'actions' => [
                            $r['action'] ?? null
                        ],
                    ];
                })
                ->values()
                ->toArray();

        } catch (\Throwable $e) {

            return [];
        }
    }

    /**
     * =========================================================
     * EXECUTIVE SUMMARY
     * =========================================================
     */
    private function buildExecutiveSummary(
        array $metrics,
        array $recommendations
    ): string {

        $score = round(
            (
                ($metrics['avg_relevance'] ?? 0)
                + ($metrics['avg_groundedness'] ?? 0)
                + ($metrics['avg_faithfulness'] ?? 0)
            ) / 3,
            2
        );

        if ($score >= 0.85) {
            return "Le système RAG présente une excellente qualité globale avec un faible niveau de risque.";
        }

        if ($score >= 0.70) {
            return "Le système RAG est globalement performant mais certaines optimisations sont recommandées.";
        }

        if ($score >= 0.55) {
            return "Le système RAG présente plusieurs faiblesses pouvant affecter la qualité des réponses.";
        }

        return "Le système RAG nécessite des améliorations importantes avant une utilisation critique.";
    }

    /**
     * =========================================================
     * RISK LEVEL
     * =========================================================
     */
    private function computeRiskLevel(array $metrics): string
    {
        $hallucination = $metrics['hallucination_rate'] ?? 0;

        if ($hallucination >= 0.6) {
            return 'critical';
        }

        if ($hallucination >= 0.35) {
            return 'high';
        }

        if ($hallucination >= 0.15) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * =========================================================
     * RETRIEVAL HEALTH
     * =========================================================
     */
    private function computeRetrievalHealth(array $metrics): string
    {
        $recall = $metrics['retrieval_score'] ?? 0;

        if ($recall >= 0.85) {
            return 'excellent';
        }

        if ($recall >= 0.70) {
            return 'good';
        }

        if ($recall >= 0.50) {
            return 'average';
        }

        return 'poor';
    }

    /**
     * =========================================================
     * GENERATION HEALTH
     * =========================================================
     */
    private function computeGenerationHealth(array $metrics): string
    {
        $score = (
                ($metrics['avg_relevance'] ?? 0)
                + ($metrics['avg_groundedness'] ?? 0)
                + ($metrics['avg_faithfulness'] ?? 0)
            ) / 3;

        if ($score >= 0.85) {
            return 'excellent';
        }

        if ($score >= 0.70) {
            return 'good';
        }

        if ($score >= 0.50) {
            return 'average';
        }

        return 'poor';
    }

    private function buildAdministratorScores(array $metrics): array
    {
        $relevance =
            $metrics['generation']['relevance']
            ?? $metrics['avg_relevance']
            ?? 0;

        $groundedness =
            $metrics['generation']['groundedness']
            ?? $metrics['avg_groundedness']
            ?? 0;

        $hallucination =
            $metrics['generation']['hallucination']
            ?? $metrics['hallucination_rate']
            ?? 0;

        $recall =
            $metrics['retrieval']['recall']
            ?? $metrics['retrieval_score']
            ?? 0;

        return [
            [
                'key' => 'response_quality',
                'label' => 'Qualité des réponses',
                'score' => round($relevance * 100),
                'description' =>
                    'Capacité du chatbot à répondre correctement aux questions.',
            ],

            [
                'key' => 'knowledge_coverage',
                'label' => 'Couverture des connaissances',
                'score' => round($recall * 100),
                'description' =>
                    'Quantité d’informations exploitables trouvées dans votre contenu.',
            ],

            [
                'key' => 'response_reliability',
                'label' => 'Fiabilité des réponses',
                'score' => round($groundedness * 100),
                'description' =>
                    'Niveau de cohérence et de fiabilité des réponses générées.',
            ],

            [
                'key' => 'error_risk',
                'label' => 'Risque d’erreur',
                'score' => round($hallucination * 100),
                'description' =>
                    'Probabilité que certaines réponses soient imprécises.',
            ],
        ];
    }
    private function buildAdministratorRecommendations(
        array $metrics,
        array $recommendations
    ): array {

        return collect($recommendations)
            ->map(function ($r) {

                return match ($r['type']) {

                    'retrieval' => [
                        'title' =>
                            'Ajouter davantage de contenu utile',

                        'description' =>
                            'Le chatbot manque parfois d’informations pour répondre précisément.',

                        'actions' => [
                            'Ajouter plus de pages explicatives',
                            'Créer des FAQ',
                            'Ajouter des descriptions détaillées',
                        ],

                        'severity' => $r['severity'],
                    ],

                    'ranking' => [
                        'title' =>
                            'Améliorer la clarté des contenus',

                        'description' =>
                            'Certaines informations importantes sont difficiles à exploiter automatiquement.',

                        'actions' => [
                            'Structurer les contenus avec des titres clairs',
                            'Éviter les blocs trop longs',
                            'Créer des contenus plus ciblés',
                        ],

                        'severity' => $r['severity'],
                    ],

                    'generation' => [
                        'title' =>
                            'Améliorer la précision des réponses',

                        'description' =>
                            'Certaines réponses peuvent manquer de précision ou de contexte.',

                        'actions' => [
                            'Ajouter des contenus plus détaillés',
                            'Renforcer les informations métier',
                            'Ajouter des exemples concrets',
                        ],

                        'severity' => $r['severity'],
                    ],

                    'grounding' => [
                        'title' =>
                            'Renforcer la cohérence des réponses',

                        'description' =>
                            'Le chatbot doit disposer de contenus plus fiables et structurés.',

                        'actions' => [
                            'Mettre à jour les contenus importants',
                            'Ajouter des informations vérifiées',
                            'Supprimer les contenus ambigus',
                        ],

                        'severity' => $r['severity'],
                    ],

                    default => [
                        'title' => $r['title'],
                        'description' => $r['description'],
                        'actions' => $r['actions'] ?? [],
                        'severity' => $r['severity'] ?? 'medium',
                    ],
                };
            })
            ->values()
            ->toArray();
    }
    private function buildAdministratorStatus(array $metrics): array
    {
        $score =
            $metrics['final']['overall_score']
            ?? 0;

        return match (true) {

            $score >= 0.85 => [
                'label' => 'Excellent',
                'color' => 'success',
                'message' =>
                    'Le chatbot répond avec une très bonne qualité.',
            ],

            $score >= 0.70 => [
                'label' => 'Bon',
                'color' => 'primary',
                'message' =>
                    'Le chatbot fonctionne correctement avec quelques améliorations possibles.',
            ],

            $score >= 0.55 => [
                'label' => 'Moyen',
                'color' => 'warning',
                'message' =>
                    'Certaines réponses pourraient être améliorées.',
            ],

            default => [
                'label' => 'Faible',
                'color' => 'danger',
                'message' =>
                    'Le chatbot nécessite davantage de contenu et d’optimisation.',
            ],
        };
    }
}
