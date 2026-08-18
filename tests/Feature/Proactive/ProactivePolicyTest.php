<?php

namespace Tests\Feature\Proactive;

use App\Domain\Proactive\ProactivePolicyEngine;
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

class ProactivePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_unsubscribe_is_respected_by_a_later_campaign_before_any_send(): void
    {
        [$account, $site, $conversation, $visitor] = $this->tenantContext();
        $agent = McpAgent::query()->create([
            'site_id' => $site->id,
            'name' => 'Sales',
            'is_active' => true,
            'can_proactively_engage' => true,
            'proactive_requires_approval' => false,
        ]);

        $oldCampaign = $this->campaign($account, $site, $agent, 'Old campaign');
        AnalyticsEvent::query()->create([
            'account_id' => $account->id,
            'site_id' => $site->id,
            'visitor_id' => $visitor->id,
            'conversation_id' => $conversation->id,
            'event_type' => 'proactive_unsubscribed',
            'source' => 'widget',
            'occurred_at' => now(),
            'idempotency_key' => hash('sha256', 'unsubscribe'),
        ]);

        $campaign = $this->campaign($account, $site, $agent, 'Later campaign');
        $sequence = ProactiveSequence::query()->create([
            'account_id' => $account->id,
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
            'conversation_id' => $conversation->id,
            'visitor_id' => $visitor->id,
            'channel' => 'website',
            'status' => 'active',
            'idempotency_key' => hash('sha256', 'later-sequence'),
        ]);
        $message = ProactiveMessage::query()->create([
            'account_id' => $account->id,
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
            'sequence_id' => $sequence->id,
            'conversation_id' => $conversation->id,
            'visitor_id' => $visitor->id,
            'agent_id' => $agent->id,
            'channel' => 'website',
            'status' => 'scheduled',
            'step' => 1,
            'scheduled_at' => now(),
            'idempotency_key' => hash('sha256', 'later-message'),
        ]);

        $decision = app(ProactivePolicyEngine::class)->evaluate($message);

        $this->assertFalse($decision['allowed']);
        $this->assertSame('visitor_opted_out', $decision['reason']);
        $this->assertTrue($oldCampaign->exists);
    }

    public function test_old_visitor_messages_do_not_block_the_first_proactive_message(): void
    {
        [$account, $site, $conversation, $visitor] = $this->tenantContext();
        $agent = McpAgent::query()->create([
            'site_id' => $site->id,
            'name' => 'Sales',
            'is_active' => true,
            'can_proactively_engage' => true,
            'proactive_requires_approval' => false,
        ]);
        $campaign = $this->campaign($account, $site, $agent, 'Kitchen quote');
        $triggeredAt = now()->subMinute();

        AnalyticsEvent::query()->create([
            'account_id' => $account->id,
            'site_id' => $site->id,
            'visitor_id' => $visitor->id,
            'conversation_id' => $conversation->id,
            'event_type' => 'message_sent',
            'source' => 'chat',
            'occurred_at' => $triggeredAt->copy()->subDay(),
            'idempotency_key' => hash('sha256', 'old-visitor-message'),
        ]);
        $triggerEvent = AnalyticsEvent::query()->create([
            'account_id' => $account->id,
            'site_id' => $site->id,
            'visitor_id' => $visitor->id,
            'conversation_id' => $conversation->id,
            'event_type' => 'message_sent',
            'source' => 'chat',
            'occurred_at' => $triggeredAt,
            'idempotency_key' => hash('sha256', 'trigger-message'),
        ]);

        $sequence = ProactiveSequence::query()->create([
            'account_id' => $account->id,
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
            'conversation_id' => $conversation->id,
            'visitor_id' => $visitor->id,
            'channel' => 'website',
            'status' => 'active',
            'context_snapshot' => ['trigger_event_id' => $triggerEvent->id],
            'idempotency_key' => hash('sha256', 'triggered-sequence'),
        ]);
        $message = ProactiveMessage::query()->create([
            'account_id' => $account->id,
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
            'sequence_id' => $sequence->id,
            'conversation_id' => $conversation->id,
            'visitor_id' => $visitor->id,
            'agent_id' => $agent->id,
            'channel' => 'website',
            'status' => 'scheduled',
            'step' => 1,
            'scheduled_at' => now(),
            'idempotency_key' => hash('sha256', 'triggered-message'),
        ]);

        $decision = app(ProactivePolicyEngine::class)->evaluate($message);

        $this->assertTrue($decision['allowed']);

        AnalyticsEvent::query()->create([
            'account_id' => $account->id,
            'site_id' => $site->id,
            'visitor_id' => $visitor->id,
            'conversation_id' => $conversation->id,
            'event_type' => 'message_sent',
            'source' => 'chat',
            'occurred_at' => now()->addSecond(),
            'idempotency_key' => hash('sha256', 'new-visitor-message'),
        ]);

        $decision = app(ProactivePolicyEngine::class)->evaluate($message->fresh());

        $this->assertFalse($decision['allowed']);
        $this->assertSame('visitor_replied', $decision['reason']);
    }

    private function tenantContext(): array
    {
        $role = Role::query()->create(['name' => 'admin-'.Str::random(8)]);
        $owner = User::factory()->create(['role_id' => $role->id]);
        $account = Account::query()->create([
            'name' => 'Account '.Str::random(8),
            'email' => Str::uuid().'@example.test',
            'owner_user_id' => $owner->id,
        ]);
        $site = Site::query()->create(['account_id' => $account->id, 'url' => 'https://'.Str::random(10).'.example.test']);
        $visitor = Visitor::query()->create(['site_id' => $site->id, 'uuid' => (string) Str::uuid()]);
        $conversation = Conversation::query()->create([
            'site_id' => $site->id,
            'visitor_id' => $visitor->id,
            'metadata' => ['channel' => 'widget'],
        ]);

        return [$account, $site, $conversation, $visitor];
    }

    private function campaign(Account $account, Site $site, McpAgent $agent, string $name): ProactiveCampaign
    {
        return ProactiveCampaign::query()->create([
            'account_id' => $account->id,
            'site_id' => $site->id,
            'agent_id' => $agent->id,
            'name' => $name,
            'status' => 'active',
            'channel' => 'website',
            'max_messages' => 3,
            'cooldown_seconds' => 0,
            'visitor_daily_cap' => 5,
            'conversation_total_cap' => 3,
        ]);
    }
}
