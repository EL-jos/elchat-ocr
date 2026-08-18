<?php

namespace App\Jobs;

use App\Domain\Sales\ProspectDiscoveryService;
use App\Domain\Sales\ProspectingPolicyEngine;
use App\Models\Conversation;
use App\Models\Sales\ProspectingCampaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Déclenché par le Laravel Scheduler selon sales_prospecting_campaigns.
 * next_run_at — driver `database` existant, workers Supervisor existants,
 * aucune nouvelle infrastructure.
 */
class RunProspectingCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly string $campaignId)
    {
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("sales-hunter-campaign:{$this->campaignId}"))->expireAfter(1800)];
    }

    public function handle(ProspectDiscoveryService $discovery, ProspectingPolicyEngine $policy): void
    {
        $campaign = ProspectingCampaign::with('config.agent', 'site')->findOrFail($this->campaignId);
        $config = $campaign->config;

        $decision = $policy->canDiscover($config, $campaign);
        if (!$decision->allowed) {
            $campaign->update(['status' => 'paused', 'stats' => array_merge($campaign->stats ?? [], ['last_block_reason' => $decision->message])]);
            return;
        }

        $campaign->update(['status' => 'running', 'started_at' => now()]);

        // Conversation interne AU NIVEAU CAMPAGNE (distincte de celle de chaque
        // prospect) — sert uniquement aux appels de découverte en masse.
        $campaignConversation = Conversation::create([
            'id' => (string) Str::uuid(), 'site_id' => $campaign->site_id,
            'user_id' => null, 'visitor_id' => null,
        ]);

        $limit = $config->limitFor('max_new_prospects_per_day', 20);
        $createdCount = $discovery->discover($campaign->site, $campaignConversation, $campaign, $config->icp, $limit);

        foreach ($campaign->prospects()->where('status', 'discovered')->get() as $prospect) {
            ProcessProspectQualificationJob::dispatch($prospect->id);
        }

        $campaign->update([
            'status' => 'completed', 'completed_at' => now(),
            'stats' => array_merge($campaign->stats ?? [], ['prospects_discovered' => $createdCount]),
        ]);
    }
}
