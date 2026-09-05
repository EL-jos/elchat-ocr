<?php

namespace App\Console\Commands;

use App\Enums\AnalyticsEventType;
use App\Models\AnalyticsEvent;
use Illuminate\Console\Command;

class PruneAnalyticsEventsCommand extends Command
{
    protected $signature = 'analytics:prune {--days=}';
    protected $description = 'Prune aggregated non-critical raw analytics events according to retention policy';

    public function handle(): int
    {
        $days = max(30, (int) ($this->option('days') ?: config('analytics.raw_event_retention_days', 180)));
        $cutoff = now()->subDays($days)->startOfDay();
        $critical = [
            AnalyticsEventType::LEAD_CREATED->value,
            AnalyticsEventType::LEAD_UPDATED->value,
            AnalyticsEventType::CONTACT_CREATED->value,
            AnalyticsEventType::OPPORTUNITY_CREATED->value,
            AnalyticsEventType::OPPORTUNITY_UPDATED->value,
            AnalyticsEventType::OPPORTUNITY_WON->value,
            AnalyticsEventType::OPPORTUNITY_LOST->value,
            AnalyticsEventType::MEETING_BOOKED->value,
            AnalyticsEventType::MEETING_CANCELLED->value,
            AnalyticsEventType::PURCHASE_COMPLETED->value,
        ];
        $deleted = 0;

        do {
            $ids = AnalyticsEvent::query()
                ->where('occurred_at', '<', $cutoff)
                ->whereNotIn('event_type', $critical)
                ->whereExists(function ($query) {
                    $query->selectRaw('1')
                        ->from('analytics_daily_metrics as adm')
                        ->whereColumn('adm.site_id', 'resource_events.site_id')
                        ->whereColumn('adm.event_type', 'resource_events.event_type')
                        ->whereRaw('adm.metric_date = DATE(resource_events.occurred_at)');
                })
                ->limit(1000)
                ->pluck('id');

            if ($ids->isNotEmpty()) {
                $deleted += AnalyticsEvent::query()->whereIn('id', $ids)->delete();
            }
        } while ($ids->isNotEmpty());

        $this->components->info("{$deleted} non-critical raw event(s) pruned; business outcomes were preserved.");
        return self::SUCCESS;
    }
}
