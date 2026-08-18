<?php

namespace App\Jobs;

use App\Models\AnalyticsEvent;
use App\Models\Site;
use App\Services\analytics\AnalyticsEventService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AggregateAnalyticsDayJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 900];
    public int $uniqueFor = 3600;

    public function __construct(public readonly string $siteId, public readonly string $date)
    {
        $this->onQueue(config('analytics.queue', 'analytics'));
    }

    public function uniqueId(): string
    {
        return "{$this->siteId}:{$this->date}";
    }

    public function handle(AnalyticsEventService $events): void
    {
        if (!config('analytics.daily_aggregation_enabled', true)) {
            return;
        }

        $site = Site::query()->select(['id', 'account_id'])->find($this->siteId);
        if (!$site) {
            return;
        }

        $day = Carbon::parse($this->date)->startOfDay();
        $rows = AnalyticsEvent::query()
            ->forSite($site)
            ->occurredBetween($day, $day->copy()->endOfDay())
            ->select(
                'event_type', 'resource_type', 'source', 'channel', 'agent_id',
                'workflow_id', 'attribution_type', 'currency',
                DB::raw('COUNT(*) as event_count'),
                DB::raw('COUNT(DISTINCT visitor_id) as unique_visitors'),
                DB::raw('COUNT(DISTINCT conversation_id) as unique_conversations'),
                DB::raw('SUM(value) as value_sum'),
            )
            ->groupBy(
                'event_type', 'resource_type', 'source', 'channel', 'agent_id',
                'workflow_id', 'attribution_type', 'currency'
            )
            ->get();

        $metrics = [];
        foreach ($rows as $row) {
            $eventType = $row->event_type;
            if (in_array($eventType, ['impression', 'click', 'conversion'], true) && $row->resource_type) {
                try {
                    $eventType = $events->canonicalResourceEventType($row->resource_type, $eventType)->value;
                } catch (InvalidArgumentException) {
                    // Preserve an unknown legacy type instead of losing observed data.
                }
            }

            $dimensions = [
                'source' => $row->source ?: null,
                'channel' => $row->channel ?: null,
                'agent_id' => $row->agent_id ?: null,
                'workflow_id' => $row->workflow_id ?: null,
                'attribution_type' => $row->attribution_type ?: null,
                'currency' => $row->currency ?: null,
            ];
            $dimensionKey = hash('sha256', json_encode($dimensions, JSON_THROW_ON_ERROR));
            $key = "{$eventType}:{$dimensionKey}";

            $metrics[$key] ??= [
                'account_id' => $site->account_id,
                'site_id' => $site->id,
                'metric_date' => $day->toDateString(),
                'event_type' => $eventType,
                ...$dimensions,
                'dimension_key' => $dimensionKey,
                'event_count' => 0,
                'unique_visitors' => 0,
                'unique_conversations' => 0,
                'value_sum' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $metrics[$key]['event_count'] += (int) $row->event_count;
            $metrics[$key]['unique_visitors'] += (int) $row->unique_visitors;
            $metrics[$key]['unique_conversations'] += (int) $row->unique_conversations;
            if ($row->value_sum !== null) {
                $metrics[$key]['value_sum'] = (float) ($metrics[$key]['value_sum'] ?? 0) + (float) $row->value_sum;
            }
        }

        DB::transaction(function () use ($site, $day, $metrics) {
            DB::table('analytics_daily_metrics')
                ->where('site_id', $site->id)
                ->where('metric_date', $day->toDateString())
                ->delete();

            foreach (array_chunk(array_values($metrics), 500) as $chunk) {
                DB::table('analytics_daily_metrics')->insert($chunk);
            }

            DB::table('analytics_daily_aggregate_runs')->updateOrInsert(
                ['site_id' => $site->id, 'metric_date' => $day->toDateString()],
                [
                    'account_id' => $site->account_id,
                    'raw_event_count' => array_sum(array_column($metrics, 'event_count')),
                    'completed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        });
    }
}
