<?php

namespace App\Jobs;
use romanzipp\QueueMonitor\Traits\IsMonitored;

use App\Domain\Sales\ProspectInformationCompletionService;
use App\Domain\Sales\ProspectCrmSyncService;
use App\Domain\Sales\SalesHunterConversationCleanupService;
use App\Models\Conversation;
use App\Models\Sales\Prospect;
use App\Services\Sales\SalesHunterRealtimeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

class SyncProspectToCrmJob implements ShouldQueue, ShouldBeUnique
{
    use IsMonitored;
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 600;

    public function __construct(private readonly string $prospectId) {}

    public function uniqueId(): string
    {
        return 'sales-hunter-crm-sync:'.$this->prospectId;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->uniqueId()))->expireAfter(600)];
    }

    public function handle(
        ProspectCrmSyncService $sync,
        ProspectInformationCompletionService $informationCompletion,
        SalesHunterConversationCleanupService $conversationCleanup,
        SalesHunterRealtimeService $realtime,
    ): void
    {
        $prospect = Prospect::with('campaign.config', 'site')->findOrFail($this->prospectId);
        if (! $prospect->campaign?->config) {
            return;
        }

        $settings = $prospect->campaign->runtimeSettings();
        if (blank($prospect->email)) {
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
        }

        $conversation = $prospect->conversation_id ? Conversation::find($prospect->conversation_id) : null;
        if (! $conversation) {
            $conversation = Conversation::create([
                'id' => (string) Str::uuid(), 'site_id' => $prospect->site_id, 'user_id' => null, 'visitor_id' => null,
                'metadata' => $conversationCleanup->temporaryMetadata('crm_sync', $prospect->id),
            ]);
            $prospect->update(['conversation_id' => $conversation->id]);
        }

        $sync->sync($prospect->fresh(), $settings['crm_connector_slug'] ?? null, $conversation);
        $prospect->refresh();
        $this->publishProspect($prospect, $realtime);
        if (in_array($prospect->crm_sync_status, ['created', 'duplicate', 'linked'], true)) {
            $conversationCleanup->cleanup($conversation);
        }
    }

    public function failed(Throwable $exception): void
    {
        $prospect = Prospect::with('campaign')->find($this->prospectId);
        Prospect::whereKey($this->prospectId)->update([
            'crm_sync_status' => 'failed',
            'crm_sync_error' => Str::limit($exception->getMessage(), 1000),
        ]);
        if ($prospect) {
            $this->publishProspect($prospect->fresh(), app(SalesHunterRealtimeService::class));
        }
    }

    private function publishProspect(?Prospect $prospect, SalesHunterRealtimeService $realtime): void
    {
        if (! $prospect) {
            return;
        }

        $realtime->publish($prospect->site_id, 'prospect_updated', [
            'campaign_id' => $prospect->campaign_id,
            'prospect' => [
                'id' => $prospect->id,
                'campaign_id' => $prospect->campaign_id,
                'name' => $prospect->name,
                'company' => $prospect->company,
                'website' => $prospect->website,
                'domain' => $prospect->domain,
                'email' => $prospect->email,
                'phone' => $prospect->phone,
                'source' => $prospect->source,
                'location' => $prospect->location,
                'sector' => $prospect->sector,
                'score' => $prospect->score,
                'score_reasons' => $prospect->score_reasons,
                'status' => $prospect->status,
                'crm_sync_status' => $prospect->crm_sync_status,
                'crm_sync_error' => $prospect->crm_sync_error,
                'last_activity_at' => optional($prospect->last_activity_at)->toISOString(),
            ],
        ]);
    }
}
