<?php

namespace App\Jobs;
use romanzipp\QueueMonitor\Traits\IsMonitored;

use App\Domain\Sales\ProspectInformationCompletionService;
use App\Domain\Sales\ProspectCrmSyncService;
use App\Domain\Sales\SalesHunterConversationCleanupService;
use App\Models\Conversation;
use App\Models\Sales\Prospect;
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
        if (in_array($prospect->crm_sync_status, ['created', 'duplicate', 'linked'], true)) {
            $conversationCleanup->cleanup($conversation);
        }
    }

    public function failed(Throwable $exception): void
    {
        Prospect::whereKey($this->prospectId)->update([
            'crm_sync_status' => 'failed',
            'crm_sync_error' => Str::limit($exception->getMessage(), 1000),
        ]);
    }
}
