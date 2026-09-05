<?php

namespace App\Console\Commands;

use App\Jobs\AggregateAnalyticsDayJob;
use App\Models\AnalyticsEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class AggregateAnalyticsCommand extends Command
{
    protected $signature = 'analytics:aggregate {--date=} {--site=} {--days=2}';
    protected $description = 'Rebuild daily ELChat Intelligence aggregates without storing personal content';

    public function handle(): int
    {
        if (!config('analytics.daily_aggregation_enabled', true)) {
            $this->components->info('Daily analytics aggregation is disabled.');
            return self::SUCCESS;
        }

        $days = max(1, min((int) $this->option('days'), 31));
        $end = $this->option('date') ? Carbon::parse($this->option('date'))->startOfDay() : now()->startOfDay();
        $dates = collect(range(0, $days - 1))->map(fn (int $offset) => $end->copy()->subDays($offset)->toDateString());
        $query = AnalyticsEvent::query()
            ->select('site_id')
            ->whereBetween('occurred_at', [$end->copy()->subDays($days - 1), $end->copy()->endOfDay()])
            ->when($this->option('site'), fn ($query, $siteId) => $query->where('site_id', $siteId))
            ->distinct()
            ->orderBy('site_id');
        $dispatched = 0;

        $query->chunk(500, function ($siteRows) use ($dates, &$dispatched) {
            foreach ($siteRows as $siteRow) {
                foreach ($dates as $date) {
                    AggregateAnalyticsDayJob::dispatch($siteRow->site_id, $date);
                    $dispatched++;
                }
            }
        });

        $this->components->info("{$dispatched} daily aggregate job(s) dispatched.");
        return self::SUCCESS;
    }
}
