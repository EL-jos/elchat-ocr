<?php

namespace App\Jobs;

use App\Domain\MCP\Orchestration\MCPActionGateService;
use App\Domain\Sales\ProspectingPolicyEngine;
use App\Domain\Sales\ProspectScoringService;
use App\Domain\Sales\WebsiteIntelligenceService;
use App\Models\Conversation;
use App\Models\Sales\Prospect;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Un job par prospect : score déterministe (PHP) PUIS délégation au LLM
 * (agent Sales Hunter, via runForAgent) pour l'analyse de site, la
 * rédaction, et la décision d'action — jamais l'inverse. Le score n'est
 * JAMAIS recalculé par le LLM (§10 du cahier des charges).
 */
class ProcessProspectQualificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly string $prospectId)
    {
    }

    public function handle(
        ProspectScoringService $scoring,
        ProspectingPolicyEngine $policy,
        MCPActionGateService $gate,
    ): void {
        $prospect = Prospect::with('campaign.config.agent', 'site')->findOrFail($this->prospectId);
        $config = $prospect->campaign->config;

        $decision = $policy->canContact($config, $prospect);
        if (!$decision->allowed) {
            $prospect->update(['status' => 'rejected', 'score_reasons' => [['points' => 0, 'reason' => $decision->message]]]);
            return;
        }

        // Étape 1 — Score déterministe SANS signaux de site (rapide, pas de LLM, pas de fetch HTTP).
        $scored = $scoring->score($prospect, $config->icp);
        $prospect->update(['score' => $scored['score'], 'score_reasons' => $scored['reasons'], 'status' => 'qualified']);

        // Étape 2 — Conversation interne dédiée à CE prospect — réutilise human-in-the-loop
        // et Memory tels quels (voir architecture §5).
        $conversation = $prospect->conversation_id
            ? Conversation::find($prospect->conversation_id)
            : Conversation::create(['id' => (string) Str::uuid(), 'site_id' => $prospect->site_id, 'user_id' => null, 'visitor_id' => null]);

        if (!$prospect->conversation_id) {
            $prospect->update(['conversation_id' => $conversation->id]);
        }

        // Étape 3 — Délégation au LLM (agent Sales Hunter) : analyse du site, rédaction,
        // action — scopé par agent->skills/workflow_ids, exactement comme un
        // agent classique en conversation.
        $instruction = $this->buildInstruction($prospect, $config);

        $gate->runForAgent(
            site: $prospect->site,
            conversation: $conversation,
            agent: $config->agent,
            question: $instruction,
            history: [],
        );
    }

    private function buildInstruction(Prospect $prospect, $config): string
    {
        return "Nouveau prospect à qualifier pour une campagne de prospection.\n\n"
            . "Nom : {$prospect->name}\nEntreprise : {$prospect->company}\nSite web : {$prospect->website}\n"
            . "Score initial (calculé automatiquement) : {$prospect->score}/100\n\n"
            . "Objectif de la campagne : {$config->objective}\n"
            . "Mode d'autonomie configuré : {$config->autonomy_mode}\n\n"
            . "Si un site web est disponible, analyse-le pour identifier une opportunité commerciale réelle. "
            . "Rédige ensuite un message de prospection UNIQUEMENT à partir d'informations réellement disponibles sur notre entreprise "
            . "(ne jamais inventer prix, produit ou avantage). Si une information nécessaire manque, signale-le au lieu de l'inventer. "
            . "Mets à jour le statut du prospect selon ta conclusion.";
    }
}
