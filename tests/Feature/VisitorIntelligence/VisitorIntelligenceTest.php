<?php

namespace Tests\Feature\VisitorIntelligence;

use App\Enums\AnalyticsEventType;
use App\Models\Account;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitorSession;
use App\Services\VisitorIntelligence\VisitorIntelligenceEventService;
use App\Services\VisitorIntelligence\VisitorIntelligenceSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class VisitorIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_events_are_batched_sanitized_and_idempotent(): void
    {
        [, , $site] = $this->tenant();
        config()->set('analytics.async', false);
        $this->assertNotContains(AnalyticsEventType::CONVERSION->value, VisitorIntelligenceEventService::browserEventTypes());

        $payload = [
            'visitor_uuid' => (string) Str::uuid(),
            'session_id' => (string) Str::uuid(),
            'events' => [[
                'event_id' => 'page-1', 'event_type' => AnalyticsEventType::PAGE_VIEW->value,
                'page_url' => 'https://tenant.example/pricing?email=private@example.test',
                'path' => '/pricing', 'metadata' => ['title' => 'Pricing', 'email' => 'private@example.test', 'text' => 'never store this'],
                'idempotency_key' => 'browser-page-1',
            ]],
        ];

        $this->withoutMiddleware()->postJson("/api/v1/widget/site/{$site->id}/visitor-intelligence/events", $payload)->assertAccepted();
        $this->withoutMiddleware()->postJson("/api/v1/widget/site/{$site->id}/visitor-intelligence/events", $payload)->assertAccepted();

        $this->assertDatabaseCount('resource_events', 1);
        $this->assertDatabaseHas('visitor_sessions', ['site_id' => $site->id, 'page_count' => 1, 'event_count' => 1]);
        $event = \App\Models\AnalyticsEvent::query()->firstOrFail();
        $this->assertSame('/pricing', $event->metadata['path']);
        $this->assertArrayNotHasKey('email', $event->metadata);
        $this->assertArrayNotHasKey('text', $event->metadata);
    }

    public function test_visual_events_store_clean_image_reference_and_cursor_position(): void
    {
        [, , $site] = $this->tenant();
        config()->set('analytics.async', false);
        $payload = [
            'visitor_uuid' => (string) Str::uuid(),
            'session_id' => (string) Str::uuid(),
            'events' => [
                [
                    'event_id' => 'image-visible', 'event_type' => AnalyticsEventType::IMAGE_DISPLAYED->value,
                    'page_url' => 'https://tenant.example/catalog', 'path' => '/catalog',
                    'metadata' => [
                        'image_url' => 'https://cdn.example/product.webp?token=private',
                        'image_width' => 320, 'image_height' => 180, 'image_x' => 20, 'image_y' => 30,
                    ],
                ],
                [
                    'event_id' => 'pointer-position', 'event_type' => AnalyticsEventType::POINTER_MOVE->value,
                    'page_url' => 'https://tenant.example/catalog', 'path' => '/catalog',
                    'metadata' => [
                        'cursor_x' => 240, 'cursor_y' => 180, 'viewport_width' => 1280,
                        'viewport_height' => 720, 'pointer_type' => 'mouse',
                    ],
                ],
            ],
        ];

        $this->withoutMiddleware()->postJson("/api/v1/widget/site/{$site->id}/visitor-intelligence/events", $payload)->assertAccepted();

        $events = \App\Models\AnalyticsEvent::query()->orderBy('occurred_at')->get();
        $image = $events->firstWhere('event_type', AnalyticsEventType::IMAGE_DISPLAYED->value);
        $pointer = $events->firstWhere('event_type', AnalyticsEventType::POINTER_MOVE->value);
        $this->assertSame('https://cdn.example/product.webp', $image->metadata['image_url']);
        $this->assertSame(240, $pointer->metadata['cursor_x']);
        $this->assertSame(720, $pointer->metadata['viewport_height']);
    }

    public function test_session_summary_keeps_observation_and_evidence_separate(): void
    {
        [, , $site] = $this->tenant();
        $visitor = Visitor::query()->create(['site_id' => $site->id, 'uuid' => (string) Str::uuid(), 'device' => 'desktop']);
        $session = VisitorSession::query()->create([
            'account_id' => $site->account_id, 'site_id' => $site->id, 'visitor_id' => $visitor->id,
            'session_key' => (string) Str::uuid(), 'started_at' => now()->subMinutes(4), 'last_seen_at' => now(),
            'ended_at' => now(), 'page_count' => 2, 'event_count' => 4, 'has_widget_interaction' => true,
            'converted' => false,
        ]);
        $events = app(\App\Services\analytics\AnalyticsEventService::class);
        $events->capture($site, AnalyticsEventType::PAGE_VIEW, ['visitor_id' => $visitor->id, 'session_id' => $session->session_key, 'source' => 'visitor_intelligence'], ['path' => '/shipping'], 'summary-page', false);
        $events->capture($site, AnalyticsEventType::PRICING_INTENT_DETECTED, ['visitor_id' => $visitor->id, 'session_id' => $session->session_key, 'source' => 'visitor_intelligence'], ['intent_level' => 'high'], 'summary-intent', false);
        $summary = app(VisitorIntelligenceSummaryService::class)->rebuild($session);

        $this->assertSame('high', $summary->intent_level);
        $this->assertNotEmpty($summary->evidence);
        $this->assertStringContainsString('Parcours observé', (string) $summary->summary);
        $this->assertDatabaseHas('visitor_opportunities', ['site_id' => $site->id, 'type' => 'high_intent_abandonment']);
    }

    public function test_visitor_intelligence_isolated_by_tenant(): void
    {
        [$owner] = $this->tenant();
        [, , $otherSite] = $this->tenant();

        $this->withoutMiddleware()->actingAs($owner)->getJson("/api/v1/site/{$otherSite->id}/visitor-intelligence/overview")->assertForbidden();
    }

    private function tenant(): array
    {
        $role = Role::query()->create(['name' => 'admin-'.Str::lower(Str::random(10))]);
        $owner = User::factory()->create(['role_id' => $role->id]);
        $account = Account::query()->create(['name' => 'Account '.Str::random(8), 'email' => Str::uuid().'@example.test', 'owner_user_id' => $owner->id]);
        $site = Site::query()->create(['account_id' => $account->id, 'url' => 'https://tenant-'.Str::lower(Str::random(8)).'.example.test']);
        return [$owner, $account, $site];
    }
}
