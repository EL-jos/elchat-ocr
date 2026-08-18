<?php

namespace Tests\Feature\Proactive;

use App\Domain\Proactive\ProactiveOutcomeService;
use App\Models\Account;
use App\Models\AnalyticsEvent;
use App\Models\Conversation;
use App\Models\Mcp\McpAgent;
use App\Models\Proactive\ProactiveCampaign;
use App\Models\Proactive\ProactiveMessage;
use App\Models\Proactive\ProactiveSequence;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProactiveOutcomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visitor_reply_stops_the_sequence_cancels_followups_and_is_attributed_once(): void
    {
        [$account, $site, $conversationId, $visitorId] = $this->tenantContext();
        [$campaign, $sequence, $message] = $this->sequence($account, $site, $conversationId, $visitorId);
        $followUp = ProactiveMessage::query()->create([
            'account_id' => $account->id, 'site_id' => $site->id, 'campaign_id' => $campaign->id,
            'sequence_id' => $sequence->id, 'conversation_id' => $conversationId, 'visitor_id' => $visitorId,
            'agent_id' => $campaign->agent_id, 'channel' => 'website', 'status' => 'scheduled', 'step' => 2,
            'scheduled_at' => now()->addDay(), 'idempotency_key' => hash('sha256', 'follow-up'),
        ]);
        $event = AnalyticsEvent::query()->create([
            'account_id' => $account->id, 'site_id' => $site->id, 'visitor_id' => $visitorId,
            'conversation_id' => $conversationId, 'event_type' => 'message_sent', 'source' => 'chat',
            'channel' => 'widget', 'occurred_at' => now()->addMinute(), 'idempotency_key' => hash('sha256', 'reply'),
        ]);

        app(ProactiveOutcomeService::class)->handle($event);
        app(ProactiveOutcomeService::class)->handle($event);

        $this->assertSame('replied', $sequence->fresh()->status);
        $this->assertSame('canceled', $followUp->fresh()->status);
        $this->assertNotNull($message->fresh()->replied_at);
        $this->assertDatabaseCount('proactive_outcomes', 1);
        $this->assertDatabaseHas('proactive_outcomes', [
            'sequence_id' => $sequence->id,
            'analytics_event_id' => $event->id,
            'outcome_type' => 'message_sent',
        ]);
    }

    public function test_conversion_is_recorded_even_when_campaign_continues_after_conversion(): void
    {
        [$account, $site, $conversationId, $visitorId] = $this->tenantContext();
        [$campaign, $sequence] = $this->sequence($account, $site, $conversationId, $visitorId, stopOnConversion: false);
        $event = AnalyticsEvent::query()->create([
            'account_id' => $account->id, 'site_id' => $site->id, 'visitor_id' => $visitorId,
            'conversation_id' => $conversationId, 'event_type' => 'meeting_booked', 'source' => 'calendar',
            'channel' => 'widget', 'value' => 0, 'currency' => 'EUR', 'occurred_at' => now()->addMinute(),
            'idempotency_key' => hash('sha256', 'meeting'),
        ]);

        app(ProactiveOutcomeService::class)->handle($event);

        $this->assertSame('active', $sequence->fresh()->status);
        $this->assertDatabaseHas('proactive_outcomes', [
            'sequence_id' => $sequence->id,
            'outcome_type' => 'meeting_booked',
            'value' => 0,
        ]);
    }

    private function tenantContext(): array
    {
        $role = Role::query()->create(['name' => 'admin-'.Str::random(8)]);
        $owner = User::factory()->create(['role_id' => $role->id]);
        $account = Account::query()->create(['name' => 'Account '.Str::random(8), 'email' => Str::uuid().'@example.test', 'owner_user_id' => $owner->id]);
        $site = Site::query()->create(['account_id' => $account->id, 'url' => 'https://'.Str::random(10).'.example.test']);
        $visitor = Visitor::query()->create(['site_id' => $site->id, 'uuid' => (string) Str::uuid()]);
        $conversation = Conversation::query()->create(['site_id' => $site->id, 'visitor_id' => $visitor->id, 'metadata' => ['channel' => 'widget']]);
        return [$account, $site, $conversation->id, $visitor->id];
    }

    private function sequence(Account $account, Site $site, string $conversationId, string $visitorId, bool $stopOnConversion = true): array
    {
        $agent = McpAgent::query()->create([
            'site_id' => $site->id, 'name' => 'Sales', 'is_active' => true,
            'can_proactively_engage' => true, 'proactive_requires_approval' => false,
        ]);
        $campaign = ProactiveCampaign::query()->create([
            'account_id' => $account->id, 'site_id' => $site->id, 'agent_id' => $agent->id,
            'name' => 'Relance', 'status' => 'active', 'channel' => 'website', 'max_messages' => 3,
            'stop_on_reply' => true, 'stop_on_conversion' => $stopOnConversion,
            'stop_on_human_handoff' => true, 'stop_on_refusal' => true, 'stop_on_unsubscribe' => true,
            'visitor_daily_cap' => 5, 'conversation_total_cap' => 3,
        ]);
        $sequence = ProactiveSequence::query()->create([
            'account_id' => $account->id, 'site_id' => $site->id, 'campaign_id' => $campaign->id,
            'conversation_id' => $conversationId, 'visitor_id' => $visitorId, 'channel' => 'website',
            'status' => 'active', 'message_count' => 1, 'last_sent_at' => now()->subMinute(),
            'idempotency_key' => hash('sha256', 'sequence-'.$campaign->id),
        ]);
        $message = ProactiveMessage::query()->create([
            'account_id' => $account->id, 'site_id' => $site->id, 'campaign_id' => $campaign->id,
            'sequence_id' => $sequence->id, 'conversation_id' => $conversationId, 'visitor_id' => $visitorId,
            'agent_id' => $agent->id, 'channel' => 'website', 'status' => 'sent', 'step' => 1,
            'content' => 'Bonjour', 'scheduled_at' => now()->subMinute(), 'sent_at' => now()->subMinute(),
            'idempotency_key' => hash('sha256', 'message-'.$sequence->id),
        ]);
        return [$campaign, $sequence, $message];
    }
}
