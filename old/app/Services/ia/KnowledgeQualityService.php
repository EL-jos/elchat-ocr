<?php

namespace App\Services\ia;

use App\Models\KnowledgeQualityScore;
use App\Models\Site;
use App\Services\vector\VectorSearchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Exception;

class KnowledgeQualityService
{
    protected EmbeddingService $embeddingService;
    protected VectorSearchService $vectorSearchService;

    public function __construct(
        EmbeddingService $embeddingService,
        VectorSearchService $vectorSearchService
    ) {
        $this->embeddingService = $embeddingService;
        $this->vectorSearchService = $vectorSearchService;
    }
    /**
     * Calcule et enregistre les scores de qualité du site.
     *
     * @param Site $site
     * @return KnowledgeQualityScore|null
     */
    public function calculateForSite(Site $site): ?KnowledgeQualityScore
    {
        try {
            // 1️⃣ Calcul des scores
            $coverage   = $this->calculateCoverage($site);
            $integrity  = $this->calculateIntegrity($site);
            $retrieval  = $this->calculateRetrieval($site);
            $redundancy = $this->calculateSemanticRedundancy($site);
            $freshness  = $this->calculateFreshness($site);
            $precision = $this->calculatePrecision($site);

            $globalScore = $this->calculateGlobalScore(compact(
                'coverage', 'integrity', 'retrieval', 'redundancy', 'freshness', 'precision',
            ));

            // 2️⃣ Recommandations
            $recommendations = $this->generateRecommendations([
                'coverage' => $coverage,
                'integrity' => $integrity,
                'retrieval' => $retrieval,
                'redundancy' => $redundancy,
                'freshness' => $freshness,
                'precision' => $precision, // <--- peut ajouter recommandations sur précision
            ]);

            // 3️⃣ Sauvegarde ou mise à jour
            $score = KnowledgeQualityScore::updateOrCreate(
                [
                    'site_id' => $site->id,
                    'scope_type' => 'global',
                ],
                [
                    'coverage_score'   => $coverage,
                    'integrity_score'  => $integrity,
                    'retrieval_score'  => $retrieval,
                    'redundancy_score' => $redundancy,
                    'freshness_score'  => $freshness,
                    'global_score'     => $globalScore,
                    'recommendations'  => $recommendations,
                    'id'               => (string) Str::uuid(),
                    'precision' => $precision,
                ]
            );

            return $score;

        } catch (\Exception $e) {
            Log::error('Erreur lors du calcul KQI : '.$e->getMessage(), [
                'site_id' => $site->id ?? null,
                'stack' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }
    /**
     * Couverture : proportion de pages avec contenu
     */
    protected function calculateCoverage(Site $site): float
    {
        $totalPages = $site->pages()->count();
        if ($totalPages === 0) return 0;

        $goodPages = $site->pages()
            ->whereNotNull('content')
            ->whereRaw('LENGTH(content) > 300')
            ->count();

        // Bonus si la page a au moins 1 chunk
        $chunkedPages = $site->pages()
            ->whereHas('chunks')
            ->count();

        $score = ($goodPages + $chunkedPages) / ($totalPages * 2) * 100;

        return round($score, 2);
    }
    /**
     * Intégrité : proportion de pages avec titre + contenu
     */
    protected function calculateIntegrity(Site $site): float
    {
        $totalPages = $site->pages()->count();
        if ($totalPages === 0) return 0;

        $completePages = $site->pages()
            ->whereNotNull('title')
            ->whereNotNull('url')
            ->whereHas('chunks') // au moins 1 chunk associé
            ->count();

        return round(($completePages / $totalPages) * 100, 2);
    }
    /**
     * Récupération : pages indexables / totales
     */
    protected function calculateRetrieval(Site $site): float
    {
        $totalPages = $site->pages()->count();
        if ($totalPages === 0) return 0;

        // Pages indexables et contenant du contenu chunks
        $retrievablePages = $site->pages()
            ->where('is_indexed', true)
            ->whereHas('chunks')
            ->count();

        $score = ($retrievablePages / $totalPages) * 100;

        return round($score, 2);
    }
    /**
     * Redondance : vérifie doublons de titre
     */
    protected function calculateRedundancy(Site $site): float
    {
        $totalChunks = DB::table('chunks')
            ->where('site_id', $site->id)
            ->count();

        if ($totalChunks === 0) return 0;

        // Count duplicates by text
        $duplicateCount = DB::table('chunks')
            ->selectRaw('COUNT(*) - 1 as duplicates')
            ->where('site_id', $site->id)
            ->groupBy('text')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->sum('duplicates');

        $score = 100 - (($duplicateCount / $totalChunks) * 100);

        return round(max($score, 0), 2);
    }
    /**
     * Fraîcheur : proportion de pages mises à jour récemment
     */
    protected function calculateFreshness(Site $site): float
    {
        $totalPages = $site->pages()->count();
        if ($totalPages === 0) return 0;

        $recentPages = $site->pages()
            ->where('updated_at', '>=', now()->subDays(30))
            ->count();

        // Bonus si chunks récents
        $recentChunks = DB::table('chunks')
            ->where('site_id', $site->id)
            ->where('updated_at', '>=', now()->subDays(30))
            ->count();

        $score = (($recentPages + $recentChunks) / ($totalPages + max($recentChunks,1))) * 100;

        return round($score, 2);
    }
    protected function calculatePrecision(Site $site): float
    {
        $pages = $site->pages()->inRandomOrder()->take(5)->get();
        $totalChunks = DB::table('chunks')->where('site_id', $site->id)->count();
        if ($totalChunks === 0 || $pages->isEmpty()) return 0;

        $scores = [];

        foreach ($pages as $page) {
            // Question type pour la page spécifique
            $testText = "Fournis un résumé du contenu et les informations importantes de la page intitulée '{$page->title}'.";

            // 1️⃣ Obtenir l'embedding
            $embedding = $this->embeddingService->getEmbedding($testText);

            // 2️⃣ Chercher les chunks les plus similaires dans Qdrant
            $results = $this->vectorSearchService->search(
                $embedding,
                $site->id,
                limit: 10,          // top 10 chunks par page
                scoreThreshold: 0.95
            );

            if (!empty($results)) {
                // Prend le meilleur score pour cette page
                $scores[] = max(array_column($results, 'score'));
            } else {
                $scores[] = 0.9;
            }
        }

        // 3️⃣ Moyenne des scores sur toutes les pages échantillonnées
        $avgScore = array_sum($scores) / count($scores);

        Log::info("Precision calculée pour le site {$site->id}", [
            'pages_count' => $pages->count(),
            'scores' => $scores,
            'avg_score' => $avgScore
        ]);

        return round($avgScore * 100, 2); // pourcentage
    }
    protected function calculateSemanticRedundancy(Site $site): float
    {
        $chunks = DB::table('chunks')
            ->where('site_id', $site->id)
            ->select('id', 'text')
            ->get();

        if ($chunks->isEmpty()) return 100;

        $duplicatePairs = 0;
        $totalComparisons = 0;

        foreach ($chunks as $chunk) {
            // Embedding de chaque chunk est déjà dans Qdrant, on l'utilise pour rechercher les doublons
            $results = $this->vectorSearchService->search(
                json_decode($chunk->embedding ?? '[]', true),
                $site->id,
                limit: 5,
                scoreThreshold: 0.95 // très strict pour détecter les vrais doublons
            );

            // On ne compte que les doublons avec d'autres chunks
            $duplicatePairs += count(array_filter($results, fn($r) => $r['id'] !== $chunk->id));
            $totalComparisons += 1;
        }

        $score = 100 - (($duplicatePairs / max($totalComparisons, 1)) * 100);

        return round(max($score, 0), 2);
    }
    /**
     * Score global : moyenne
     */
    protected function calculateGlobalScore(array $scores): float
    {
        return round(array_sum($scores) / count($scores), 2);
    }
    /**
     * Recommandations basées sur les scores
     */
    protected function generateRecommendations(array $scores): array
    {
        $recs = [];

        if ($scores['coverage'] < 90) $recs[] = 'Améliorer la couverture du contenu';
        if ($scores['retrieval'] < 80) $recs[] = 'Optimiser l’accès aux données';
        if ($scores['redundancy'] < 95) $recs[] = 'Supprimer les doublons';
        if ($scores['freshness'] < 95) $recs[] = 'Mettre à jour le contenu obsolète';
        if ($scores['precision'] < 85) {
            $recs[] = 'Améliorer la précision et la pertinence des réponses via les contenus du site';
        }

        return $recs;
    }
}
