<?php

namespace App\Jobs;

use App\Domain\Sales\ProspectCrmSyncService;
use App\Domain\Sales\ProspectInformationCompletionService;
use App\Domain\Sales\ProspectingPolicyEngine;
use App\Domain\Sales\ProspectingRunCompletionService;
use App\Domain\Sales\ProspectScoringService;
use App\Domain\Sales\SalesHunterConversationCleanupService;
use App\Domain\Sales\WebsiteIntelligenceService;
use App\Enums\AnalyticsEventType;
use App\Models\Conversation;
use App\Models\Sales\Prospect;
use App\Services\analytics\AnalyticsEventService;
use App\Services\mcp\MCPActionGateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

/** Enrichit, score, synchronise puis délègue l'analyse commerciale à l'agent installé. */
class ProcessProspectQualificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly string $prospectId) {}

    public function handle(
        ProspectScoringService $scoring,
        WebsiteIntelligenceService $intelligence,
        ProspectInformationCompletionService $informationCompletion,
        ProspectCrmSyncService $crmSync,
        ProspectingPolicyEngine $policy,
        MCPActionGateService $gate,
        AnalyticsEventService $analytics,
        ProspectingRunCompletionService $completion,
        SalesHunterConversationCleanupService $conversationCleanup,
    ): void {
        $prospect = Prospect::with('campaign.config.agent', 'site')->findOrFail($this->prospectId);
        if ($prospect->status !== 'discovered') {
            return;
        }

        $campaign = $prospect->campaign;
        $config = $campaign?->config;
        if (! $campaign || ! $config || ($campaign->stats['stopped_manually'] ?? false) === true) {
            $prospect->update([
                'status' => 'rejected',
                'score_reasons' => [['points' => 0, 'reason' => 'La campagne a été arrêtée avant la qualification.', 'basis' => 'policy']],
            ]);
            if ($prospect->run) {
                $completion->finishIfReady($prospect->run);
            }

            return;
        }

        $settings = $prospect->campaign->runtimeSettings();
        $decision = $policy->canContact($config, $prospect);
        // Les horaires et le quota sortant ne doivent pas empêcher la
        // qualification ni la complétion de la fiche. Seuls un désabonnement
        // ou une adresse définitivement invalide rejettent le prospect.
        if (! $decision->allowed && in_array($decision->reasonCode, ['do_not_contact', 'invalid_email_address'], true)) {
            $prospect->update(['status' => 'rejected', 'score_reasons' => [['points' => 0, 'reason' => $decision->message, 'basis' => 'policy']]]);
            $analytics->capture($prospect->site, AnalyticsEventType::PROSPECT_CANDIDATE_REJECTED, [
                'resource_type' => 'sales_prospect', 'resource_id' => $prospect->id,
            ], ['reason' => $decision->reasonCode], async: true);
            if ($prospect->run) {
                $completion->finishIfReady($prospect->run);
            }

            return;
        }
        $completionResult = $informationCompletion->complete($prospect, $settings['icp'] ?? []);
        if ($completionResult['fields'] !== []) {
            $prospect->update($completionResult['fields']);
            $enrichment = $prospect->enrichment_data ?? [];
            $enrichment['profile_completion'] = [
                'completed_fields' => array_keys($completionResult['fields']),
                'completed_at' => now()->toIso8601String(),
            ];
            $prospect->update(['enrichment_data' => $enrichment]);
            foreach ($completionResult['evidence'] as $evidence) {
                $prospect->evidence()->create([
                    'kind' => $evidence['type'] ?? 'observation',
                    'source_key' => 'web_search_completion',
                    'source_url' => $evidence['source_url'] ?? null,
                    'field' => $evidence['field'] ?? 'public_profile_completion',
                    'value' => $evidence['value'] ?? null,
                    'confidence' => $evidence['confidence'] ?? null,
                    'observed_at' => now(),
                ]);
            }
            $prospect->refresh();
        }

        $signals = $prospect->website
            ? $intelligence->analyze($prospect->website, (int) ($settings['limits']['max_pages_per_prospect'] ?? 3), $settings['icp'] ?? [])
            : [];
        if ($signals) {
            $enrichment = $prospect->enrichment_data ?? [];
            $enrichment['website_intelligence'] = $signals;
            $prospect->update(['enrichment_data' => $enrichment]);
            $prospect->evidence()->create([
                'kind' => 'observation', 'source_key' => 'website_enrichment', 'source_url' => $prospect->website,
                'field' => 'website_signals', 'value' => $signals, 'confidence' => 0.8, 'observed_at' => now(),
            ]);
            $analytics->capture($prospect->site, AnalyticsEventType::PROSPECT_CANDIDATE_ENRICHED, [
                'resource_type' => 'sales_prospect', 'resource_id' => $prospect->id,
            ], ['website' => $prospect->website], async: true);
        }

        $qualificationConfig = $settings['discovery_settings'] ?? [];
        $scored = $scoring->score($prospect, $settings['icp'] ?? [], $signals, $qualificationConfig['qualification'] ?? []);
        $prospect->update([
            'score' => $scored['score'], 'score_reasons' => $scored['reasons'],
            'qualification_data' => ['minimum_score' => (int) ($settings['minimum_score'] ?? 70), 'signals' => $signals],
        ]);
        foreach ($scored['reasons'] as $reason) {
            $prospect->evidence()->create([
                'kind' => $reason['basis'] ?? 'inference', 'source_key' => 'qualification',
                'field' => 'score_reason', 'value' => $reason, 'confidence' => 1.0, 'observed_at' => now(),
            ]);
        }
        $analytics->capture($prospect->site, AnalyticsEventType::PROSPECT_CANDIDATE_SCORED, [
            'resource_type' => 'sales_prospect', 'resource_id' => $prospect->id,
        ], ['score' => $scored['score']], async: true);

        if ($scored['score'] < (int) ($settings['minimum_score'] ?? 70)) {
            $prospect->update(['status' => 'rejected']);
            $analytics->capture($prospect->site, AnalyticsEventType::PROSPECT_CANDIDATE_REJECTED, [
                'resource_type' => 'sales_prospect', 'resource_id' => $prospect->id,
            ], ['reason' => 'minimum_score', 'score' => $scored['score']], async: true);
            if ($prospect->run) {
                $completion->finishIfReady($prospect->run);
            }

            return;
        }

        $prospect->update(['status' => 'qualified']);
        $analytics->capture($prospect->site, AnalyticsEventType::PROSPECT_CANDIDATE_QUALIFIED, [
            'resource_type' => 'sales_prospect', 'resource_id' => $prospect->id,
        ], ['score' => $scored['score']], async: true);

        $conversation = $prospect->conversation_id ? Conversation::find($prospect->conversation_id) : null;
        if (! $conversation) {
            $conversation = Conversation::create([
                'id' => (string) Str::uuid(), 'site_id' => $prospect->site_id, 'user_id' => null, 'visitor_id' => null,
                'metadata' => $conversationCleanup->temporaryMetadata('prospect_qualification', $prospect->id),
            ]);
            $prospect->update(['conversation_id' => $conversation->id]);
        }

        $crmSync->sync($prospect->fresh(), $settings['crm_connector_slug'] ?? null, $conversation);
        $prospect->refresh();
        $crmSynchronized = in_array($prospect->crm_sync_status, ['created', 'duplicate', 'linked'], true);

        if (! $decision->allowed) {
            $qualification = $prospect->qualification_data ?? [];
            $qualification['contact_policy'] = [
                'allowed' => false,
                'reason' => $decision->reasonCode,
                'message' => $decision->message,
            ];
            $prospect->update(['qualification_data' => $qualification]);
            if ($prospect->run) {
                $completion->finishIfReady($prospect->run);
            }
            if ($crmSynchronized) {
                $conversationCleanup->cleanup($conversation);
            }

            return;
        }

        try {
            $gate->runForAgent(
                site: $prospect->site,
                conversation: $conversation,
                agent: $config->agent,
                question: $this->buildInstruction($prospect->fresh(), $config),
                history: [],
                systemActor: true,
            );
        } finally {
            if ($crmSynchronized) {
                $conversationCleanup->cleanup($conversation);
            }
        }
        if ($prospect->run) {
            $completion->finishIfReady($prospect->run);
        }
    }

    private function buildInstruction(Prospect $prospect, $config): string
    {
        return "Nouveau prospect à qualifier pour la campagne Sales Hunter.\n\n"
            ."Entreprise : {$prospect->company}\nSite web : {$prospect->website}\n"
            ."Adresse : {$prospect->address}\nTéléphone : {$prospect->phone}\nEmail : {$prospect->email}\n"
            ."Contact identifié : {$prospect->contact_person}\nAutre contact : {$prospect->other_contact}\n"
            ."Score déterministe : {$prospect->score}/100\nDonnées observées : ".json_encode($prospect->enrichment_data ?? [], JSON_UNESCAPED_UNICODE)."\n\n"
            ."Objectif : {$config->objective}. Utilise la base de connaissances du tenant pour comprendre notre offre et ses critères ICP. "
            .'Sépare explicitement les faits observés, les inférences prudentes et les recommandations. '
            ."Ne rédige aucun message ni promesse à partir d'informations absentes. Mets à jour le statut seulement si les éléments observés le justifient.";
    }

    public function failed(Throwable $exception): void
    {
        $prospect = Prospect::with('site', 'run')->find($this->prospectId);
        if (! $prospect) {
            return;
        }

        $prospect->update([
            'status' => 'rejected', 'crm_sync_status' => $prospect->crm_sync_status === 'created' ? $prospect->crm_sync_status : 'failed',
            'crm_sync_error' => $exception->getMessage(),
            'score_reasons' => [['points' => 0, 'reason' => 'La qualification a échoué après les tentatives de reprise.', 'basis' => 'policy']],
        ]);
        app(AnalyticsEventService::class)->capture($prospect->site, AnalyticsEventType::PROSPECT_CANDIDATE_REJECTED, [
            'resource_type' => 'sales_prospect', 'resource_id' => $prospect->id,
        ], ['reason' => 'qualification_failed'], async: false);
        if ($prospect->run) {
            app(ProspectingRunCompletionService::class)->finishIfReady($prospect->run);
        }
    }
}
