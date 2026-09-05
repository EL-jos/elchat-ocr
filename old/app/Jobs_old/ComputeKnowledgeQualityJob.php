<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\ia\KnowledgeQualityService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ComputeKnowledgeQualityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public ?string $siteId;
    public int $timeout = 600; // 10 minutes

    /**
     * Create a new job instance.
     * @param string|null $siteId pour recalculer un site spécifique ou tous
     */
    public function __construct(?string $siteId = null)
    {
        $this->siteId = $siteId;
    }

    /**
     * Execute the job.
     */
    public function handle(KnowledgeQualityService $service)
    {
        // Récupère le(s) site(s)
        $sites = $this->siteId
            ? Site::where('id', $this->siteId)->get()
            : Site::all();

        if ($sites->isEmpty()) {
            Log::warning('Aucun site trouvé pour le calcul KQI', [
                'site_id' => $this->siteId,
            ]);
            return;
        }

        foreach ($sites as $site) {
            try {
                $score = $service->calculateForSite($site);

                if (!$score) {
                    Log::error("Impossible de calculer le KQI pour le site {$site->id}");
                    continue; // passe au site suivant
                }

                Log::info("KQI recalculé pour le site {$site->id}", [
                    'coverage_score'   => $score->coverage_score,
                    'integrity_score'  => $score->integrity_score,
                    'retrieval_score'  => $score->retrieval_score,
                    'redundancy_score' => $score->redundancy_score,
                    'freshness_score'  => $score->freshness_score,
                    'global_score'     => $score->global_score,
                    'recommendations'  => $score->recommendations,
                ]);
            } catch (\Exception $e) {
                Log::error("Erreur lors du calcul KQI pour le site {$site->id}", [
                    'error' => $e->getMessage(),
                    'stack' => $e->getTraceAsString(),
                ]);
            }
        }
    }
}
