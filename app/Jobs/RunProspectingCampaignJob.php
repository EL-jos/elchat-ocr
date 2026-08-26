<?php

namespace App\Jobs;

use App\Domain\Sales\ProspectDiscoveryService;
use App\Domain\Sales\ProspectingPolicyEngine;
use App\Domain\Sales\ProspectingRunCompletionService;
use App\Domain\Sales\SalesHunterConversationCleanupService;
use App\Enums\AnalyticsEventType;
use App\Models\Conversation;
use App\Models\Sales\ProspectingCampaign;
use App\Models\Sales\ProspectingRun;
use App\Services\analytics\AnalyticsEventService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

class RunProspectingCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private string $trigger = 'scheduled';

    private string $runKey = '';

    public function __construct(private readonly string $campaignId, string $trigger = 'scheduled')
    {
        $this->trigger = in_array($trigger, ['scheduled', 'manual', 'forced'], true) ? $trigger : 'manual';
        $this->runKey = $this->trigger === 'scheduled'
            ? 'campaign:'.$campaignId.':scheduled:'.now()->format('YmdHi')
            : 'campaign:'.$campaignId.':'.$this->trigger.':'.(string) Str::uuid();
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("sales-hunter-campaign:{$this->campaignId}"))->expireAfter(1800)];
    }

    public function handle(
        ProspectDiscoveryService $discovery,
        ProspectingPolicyEngine $policy,
        AnalyticsEventService $analytics,
        ProspectingRunCompletionService $completion,
        SalesHunterConversationCleanupService $conversationCleanup,
    ): void {
        $campaign = ProspectingCampaign::with('config.agent', 'site')->findOrFail($this->campaignId);
        if (($campaign->stats['stopped_manually'] ?? false) === true) {
            return;
        }

        $config = $campaign->config;
        if (! $config || ! $campaign->site) {
            $campaign->update([
                'status' => 'paused',
                'next_run_at' => null,
                'stats' => array_merge($campaign->stats ?? [], ['last_block_reason' => 'agent_uninstalled']),
            ]);

            return;
        }
        $settings = $campaign->runtimeSettings();

        $decision = $policy->canDiscover($config, $campaign);
        if (! $decision->allowed) {
            $campaign->update(['status' => 'paused', 'stats' => array_merge($campaign->stats ?? [], ['last_block_reason' => $decision->message])]);

            return;
        }

        $campaign->update(['status' => 'running', 'started_at' => now(), 'completed_at' => null, 'stats' => array_merge($campaign->stats ?? [], [
            'last_run_trigger' => $this->trigger,
        ])]);
        $campaign->refresh();
        if (($campaign->stats['stopped_manually'] ?? false) === true) {
            return;
        }
        $analytics->capture($campaign->site, AnalyticsEventType::PROSPECTING_CAMPAIGN_STARTED, [
            'resource_type' => 'sales_prospecting_campaign', 'resource_id' => $campaign->id,
        ], ['sources' => $campaign->sources_snapshot ?? ($settings['sources'] ?? [])], async: true);

        $idempotencyKey = $this->runKey ?: 'campaign:'.$campaign->id.':scheduled:'.now()->format('YmdHi');
        $run = ProspectingRun::firstOrCreate(
            ['campaign_id' => $campaign->id, 'idempotency_key' => $idempotencyKey],
            ['status' => 'running', 'started_at' => now(), 'stats' => ['trigger' => $this->trigger]],
        );
        if ($run->status !== 'running') {
            return;
        }

        $campaignConversation = Conversation::create([
            'id' => (string) Str::uuid(), 'site_id' => $campaign->site_id, 'user_id' => null, 'visitor_id' => null,
            'metadata' => $conversationCleanup->temporaryMetadata('campaign_discovery'),
        ]);
        try {
            $limit = (int) ($settings['limits']['max_prospects_per_run'] ?? $settings['limits']['max_prospects_per_campaign'] ?? 50);
            $createdCount = $discovery->discover($campaign->site, $campaignConversation, $campaign, $settings['icp'] ?? [], $limit, $run);
            $prospects = $run->prospects()->where('status', 'discovered')->get();

            foreach ($prospects as $prospect) {
                ProcessProspectQualificationJob::dispatch($prospect->id);
            }
            $run->update(['stats' => array_merge($run->stats ?? [], ['prospects_discovered' => $createdCount, 'qualification_jobs' => $prospects->count()])]);
            $campaign->update(['stats' => array_merge($campaign->stats ?? [], ['prospects_discovered' => $createdCount, 'last_run_id' => $run->id])]);

            if ($prospects->isEmpty()) {
                $completion->finishIfReady($run->fresh());
            }
        } finally {
            $conversationCleanup->cleanup($campaignConversation);
        }
    }

    public function failed(Throwable $exception): void
    {
        $campaign = ProspectingCampaign::with('site')->find($this->campaignId);
        if (! $campaign) {
            return;
        }

        $campaign->runs()->where('status', 'running')->update([
            'status' => 'failed',
            'completed_at' => now(),
            'error_message' => $exception->getMessage(),
            'stats' => ['last_error' => $exception->getMessage(), 'trigger' => $this->trigger ?: 'scheduled'],
        ]);
        $campaign->update(['status' => 'failed', 'completed_at' => now(), 'stats' => array_merge($campaign->stats ?? [], [
            'last_error' => $exception->getMessage(),
        ])]);
        app(AnalyticsEventService::class)->capture($campaign->site, AnalyticsEventType::PROSPECTING_CAMPAIGN_FAILED, [
            'resource_type' => 'sales_prospecting_campaign', 'resource_id' => $campaign->id,
        ], ['error' => $exception->getMessage()], async: false);
    }
}
