<?php

namespace App\Domain\Sales;

use App\Enums\AnalyticsEventType;
use App\Models\Sales\ProspectingReport;
use App\Models\Sales\ProspectingRun;
use App\Services\analytics\AnalyticsEventService;
use App\Services\Sales\SalesHunterRealtimeService;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ProspectingRunCompletionService
{
    public function __construct(
        private readonly AnalyticsEventService $analytics,
        private readonly SalesHunterRealtimeService $realtime,
    ) {}

    public function finishIfReady(ProspectingRun $run): void
    {
        $run->refresh();
        if ($run->status !== 'running' || $run->prospects()->where('status', 'discovered')->exists()) {
            return;
        }

        $prospects = $run->prospects()->get();
        $stats = [
            'prospects_found' => $prospects->count(),
            'prospects_qualified' => $prospects->where('status', 'qualified')->count(),
            'prospects_rejected' => $prospects->where('status', 'rejected')->count(),
            'crm_created' => $prospects->where('crm_sync_status', 'created')->count(),
            'crm_duplicates' => $prospects->where('crm_sync_status', 'duplicate')->count(),
            'crm_pending' => $prospects->whereIn('crm_sync_status', ['pending_crm', 'pending_email', 'pending_contact_info', 'failed'])->count(),
            'sources' => $prospects->groupBy('source')->map->count()->all(),
        ];
        $completed = ProspectingRun::whereKey($run->id)->where('status', 'running')->update([
            'status' => 'completed', 'stats' => array_merge($run->stats ?? [], $stats), 'completed_at' => now(), 'updated_at' => now(),
        ]);
        if ($completed === 0) {
            return;
        }
        ProspectingReport::create([
            'id' => (string) Str::uuid(), 'campaign_id' => $run->campaign_id,
            'generated_at' => now(), 'stats' => $stats,
            'insights' => $this->insights($prospects, $stats),
        ]);
        $campaign = $run->campaign()->first();
        if ($campaign) {
            $campaign->update([
                'status' => ($campaign->stats['stopped_manually'] ?? false) === true ? 'paused' : 'completed',
                'completed_at' => ($campaign->stats['stopped_manually'] ?? false) === true ? $campaign->completed_at : now(),
                'stats' => array_merge($campaign->stats ?? [], $stats),
            ]);
        }
        if ($campaign) {
            $this->analytics->capture($campaign->site, AnalyticsEventType::PROSPECTING_CAMPAIGN_COMPLETED, [
                'resource_type' => 'sales_prospecting_campaign', 'resource_id' => $campaign->id,
            ], $stats, async: true);
            $this->realtime->publish($campaign->site_id, 'campaign_completed', [
                'campaign' => $this->campaignPayload($campaign->fresh()),
                'run_id' => $run->id,
                'stats' => $stats,
            ]);
        }
    }

    private function campaignPayload($campaign): array
    {
        return Arr::only($campaign->toArray(), [
            'id', 'site_id', 'name', 'status', 'next_run_at', 'started_at', 'completed_at', 'stats',
        ]);
    }

    private function insights($prospects, array $stats): array
    {
        $insights = [];
        if (($stats['crm_pending'] ?? 0) > 0) {
            $insights[] = ['category' => 'crm', 'text' => 'Certains prospects restent en attente de synchronisation CRM ou de coordonnées de contact.'];
        }
        if (($stats['prospects_rejected'] ?? 0) > 0) {
            $insights[] = ['category' => 'qualification', 'text' => 'Des candidats ont été écartés car leur score était inférieur au seuil configuré.'];
        }
        foreach ($prospects->groupBy('source') as $source => $items) {
            $insights[] = ['category' => 'source', 'text' => "La source {$source} a produit {$items->count()} prospect(s) conservé(s)."];
        }

        return $insights;
    }
}
