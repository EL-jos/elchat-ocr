<?php

namespace Tests\Feature\Analytics;

use App\Enums\AnalyticsAttributionType;
use App\Enums\AnalyticsEventType;
use App\Jobs\AggregateAnalyticsDayJob;
use App\Models\Account;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\analytics\AnalyticsEventService;
use App\Services\analytics\AnalyticsQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnalyticsIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_sanitized_events_idempotently(): void
    {
        [, , $site] = $this->tenant();
        $events = app(AnalyticsEventService::class);
        $key = $events->deterministicKey('test', $site->id, 'conversation-started');

        $first = $events->capture(
            $site,
            AnalyticsEventType::CONVERSATION_STARTED,
            ['source' => 'widget', 'session_id' => 'session-1'],
            ['intent' => 'pricing', 'email' => 'private@example.test', 'nested' => ['token' => 'secret', 'safe' => true]],
            $key,
            false,
        );
        $second = $events->capture($site, AnalyticsEventType::CONVERSATION_STARTED, [], [], $key, false);

        $this->assertNotNull($first);
        $this->assertSame($first->id, $second?->id);
        $this->assertDatabaseCount('resource_events', 1);
        $this->assertSame('pricing', $first->metadata['intent']);
        $this->assertArrayNotHasKey('email', $first->metadata);
        $this->assertSame(['safe' => true], $first->metadata['nested']);
    }

    public function test_overview_and_revenue_are_strictly_isolated_by_site(): void
    {
        [, , $siteA] = $this->tenant();
        [, , $siteB] = $this->tenant();
        $events = app(AnalyticsEventService::class);

        $this->record($events, $siteA, AnalyticsEventType::LEAD_CREATED, 'lead-a');
        $this->record($events, $siteA, AnalyticsEventType::PURCHASE_COMPLETED, 'purchase-a', [
            'value' => 125.50,
            'currency' => 'EUR',
            'attribution_type' => AnalyticsAttributionType::DIRECT->value,
        ]);
        $this->record($events, $siteB, AnalyticsEventType::LEAD_CREATED, 'lead-b');
        $this->record($events, $siteB, AnalyticsEventType::PURCHASE_COMPLETED, 'purchase-b', [
            'value' => 999,
            'currency' => 'EUR',
            'attribution_type' => AnalyticsAttributionType::DIRECT->value,
        ]);

        $overview = app(AnalyticsQueryService::class)->overview($siteA, $this->currentPeriod());
        $kpis = collect($overview['kpis'])->keyBy('key');

        $this->assertSame(1, $kpis['leads_generated']['value']);
        $this->assertSame(1, $kpis['purchases']['value']);
        $this->assertSame(125.5, $kpis['revenue_attributed']['value']);
        $this->assertSame('EUR', $overview['data_quality']['revenue_currency']);
    }

    public function test_funnel_counts_only_ordered_correlated_paths(): void
    {
        [, , $site] = $this->tenant();
        $events = app(AnalyticsEventService::class);
        $base = now()->subHour();
        $steps = [
            AnalyticsEventType::WIDGET_OPENED,
            AnalyticsEventType::CONVERSATION_STARTED,
            AnalyticsEventType::INTENT_DETECTED,
            AnalyticsEventType::LEAD_CREATED,
        ];

        foreach ($steps as $index => $eventType) {
            $this->record($events, $site, $eventType, "ordered-{$index}", [
                'correlation_id' => 'journey-1',
                'occurred_at' => $base->copy()->addMinutes($index),
            ]);
        }
        $this->record($events, $site, AnalyticsEventType::LEAD_CREATED, 'unrelated-lead', [
            'correlation_id' => 'journey-2',
            'occurred_at' => $base->copy()->addMinutes(5),
        ]);

        $funnel = app(AnalyticsQueryService::class)->funnel(
            $site,
            $this->currentPeriod(),
            array_map(fn (AnalyticsEventType $type) => $type->value, $steps),
        );

        $this->assertSame([1, 1, 1, 1], collect($funnel['steps'])->pluck('volume')->all());
    }

    public function test_recommendations_and_anomalies_are_backed_by_observed_samples(): void
    {
        config()->set('analytics.insight_minimum_sample', 10);
        config()->set('analytics.anomaly_relative_threshold', 0.25);
        [, , $site] = $this->tenant();
        $events = app(AnalyticsEventService::class);
        $current = Carbon::parse('2026-08-08 12:00:00');
        $previous = Carbon::parse('2026-08-03 12:00:00');

        for ($index = 0; $index < 20; $index++) {
            $this->record($events, $site, AnalyticsEventType::CONVERSATION_STARTED, "previous-{$index}", ['occurred_at' => $previous]);
        }
        for ($index = 0; $index < 5; $index++) {
            $this->record($events, $site, AnalyticsEventType::CONVERSATION_STARTED, "current-{$index}", ['occurred_at' => $current]);
        }
        for ($index = 0; $index < 20; $index++) {
            $this->record($events, $site, AnalyticsEventType::CTA_IMPRESSION, "poor-impression-{$index}", [
                'resource_type' => 'cta', 'resource_id' => 'poor-cta', 'label' => 'Demander un devis', 'occurred_at' => $current,
            ]);
            $this->record($events, $site, AnalyticsEventType::CTA_IMPRESSION, "good-impression-{$index}", [
                'resource_type' => 'cta', 'resource_id' => 'good-cta', 'label' => 'Réserver une démo', 'occurred_at' => $current,
            ]);
            if ($index < 10) {
                $this->record($events, $site, AnalyticsEventType::CTA_CLICK, "good-click-{$index}", [
                    'resource_type' => 'cta', 'resource_id' => 'good-cta', 'label' => 'Réserver une démo', 'occurred_at' => $current,
                ]);
            }
        }

        $filters = ['from' => '2026-08-08', 'to' => '2026-08-14'];
        $analytics = app(AnalyticsQueryService::class);
        $recommendations = $analytics->recommendations($site, $filters)['recommendations'];
        $anomalies = $analytics->anomalies($site, $filters)['anomalies'];

        $this->assertTrue(collect($recommendations)->contains(fn ($item) => $item['category'] === 'cta' && $item['observed_data']['impressions'] === 20));
        $this->assertTrue(collect($anomalies)->contains(fn ($item) => $item['metric'] === 'conversation_volume' && $item['current_value'] === 5 && $item['previous_value'] === 20));
    }

    public function test_daily_aggregation_is_rebuildable_and_idempotent(): void
    {
        [, , $site] = $this->tenant();
        $events = app(AnalyticsEventService::class);
        $day = now()->startOfDay();
        $this->record($events, $site, AnalyticsEventType::LEAD_CREATED, 'aggregate-1', ['occurred_at' => $day->copy()->addHour()]);
        $this->record($events, $site, AnalyticsEventType::LEAD_CREATED, 'aggregate-2', ['occurred_at' => $day->copy()->addHours(2)]);
        $job = new AggregateAnalyticsDayJob($site->id, $day->toDateString());

        $job->handle($events);
        $job->handle($events);

        $this->assertDatabaseCount('analytics_daily_metrics', 1);
        $this->assertDatabaseCount('analytics_daily_aggregate_runs', 1);
        $this->assertDatabaseHas('analytics_daily_metrics', [
            'site_id' => $site->id,
            'metric_date' => $day->toDateString(),
            'event_type' => AnalyticsEventType::LEAD_CREATED->value,
            'event_count' => 2,
        ]);
    }

    public function test_analytics_api_rejects_another_tenants_site(): void
    {
        [$owner] = $this->tenant();
        [, , $otherSite] = $this->tenant();

        $this->withoutMiddleware()
            ->actingAs($owner)
            ->getJson("/api/site/{$otherSite->id}/analytics/overview")
            ->assertForbidden();
    }

    private function tenant(): array
    {
        $role = Role::query()->create(['name' => 'admin-'.Str::lower(Str::random(10))]);
        $owner = User::factory()->create(['role_id' => $role->id]);
        $account = Account::query()->create([
            'name' => 'Account '.Str::random(8),
            'email' => Str::uuid().'@example.test',
            'owner_user_id' => $owner->id,
        ]);
        $site = Site::query()->create([
            'account_id' => $account->id,
            'url' => 'https://'.Str::lower(Str::random(12)).'.example.test',
        ]);

        return [$owner, $account, $site];
    }

    private function record(
        AnalyticsEventService $events,
        Site $site,
        AnalyticsEventType $type,
        string $key,
        array $context = []
    ): void {
        $events->capture($site, $type, $context, [], $key, false);
    }

    private function currentPeriod(): array
    {
        return ['from' => now()->subDay()->toDateString(), 'to' => now()->toDateString()];
    }
}
