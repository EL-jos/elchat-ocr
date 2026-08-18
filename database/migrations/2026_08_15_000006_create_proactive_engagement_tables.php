<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proactive_campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('site_id');
            $table->uuid('agent_id')->nullable();
            $table->uuid('workflow_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 24)->default('draft');
            $table->string('channel', 32)->default('website');
            $table->string('decision_mode', 20)->default('hybrid');
            $table->string('widget_behavior', 32)->default('notification_only');
            $table->unsignedTinyInteger('priority')->default(5);
            $table->string('timezone', 64)->default('UTC');
            $table->json('allowed_days')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->unsignedInteger('first_delay_seconds')->default(0);
            $table->json('follow_up_intervals')->nullable();
            $table->unsignedTinyInteger('max_messages')->default(1);
            $table->unsignedInteger('cooldown_seconds')->default(86400);
            $table->unsignedSmallInteger('site_daily_cap')->default(500);
            $table->unsignedSmallInteger('visitor_daily_cap')->default(2);
            $table->unsignedSmallInteger('conversation_total_cap')->default(3);
            $table->boolean('stop_on_reply')->default(true);
            $table->boolean('stop_on_conversion')->default(true);
            $table->boolean('stop_on_human_handoff')->default(true);
            $table->boolean('stop_on_refusal')->default(true);
            $table->boolean('stop_on_unsubscribe')->default(true);
            $table->string('context_query')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'status'], 'pe_cam_site_st_idx');
            $table->index(['account_id', 'status'], 'pe_cam_acct_st_idx');
            $table->foreign('account_id', 'pe_cam_acct_fk')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('site_id', 'pe_cam_site_fk')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('agent_id', 'pe_cam_agent_fk')->references('id')->on('mcp_agents')->nullOnDelete();
            $table->foreign('workflow_id', 'pe_cam_wf_fk')->references('id')->on('mcp_workflows')->nullOnDelete();
            $table->foreign('created_by', 'pe_cam_user_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('proactive_triggers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id');
            $table->string('type', 24)->default('event');
            $table->string('event_type', 64)->nullable();
            $table->string('condition_mode', 8)->default('all');
            $table->json('conditions')->nullable();
            $table->json('schedule')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedTinyInteger('priority')->default(5);
            $table->timestamps();

            $table->index(['event_type', 'is_active'], 'pe_trg_evt_act_idx');
            $table->index(['campaign_id', 'is_active'], 'pe_trg_cam_act_idx');
            $table->foreign('campaign_id', 'pe_trg_cam_fk')->references('id')->on('proactive_campaigns')->cascadeOnDelete();
        });

        Schema::create('proactive_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('site_id');
            $table->uuid('campaign_id');
            $table->uuid('conversation_id')->nullable();
            $table->uuid('visitor_id')->nullable();
            $table->uuid('social_conversation_id')->nullable();
            $table->string('channel', 32);
            $table->string('status', 24)->default('active');
            $table->unsignedTinyInteger('current_step')->default(0);
            $table->unsignedTinyInteger('message_count')->default(0);
            $table->timestamp('next_scheduled_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->string('stop_reason', 64)->nullable();
            $table->json('context_snapshot')->nullable();
            $table->json('evidence')->nullable();
            $table->char('idempotency_key', 64);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'idempotency_key'], 'pe_seq_site_idem_uq');
            $table->index(['site_id', 'status', 'next_scheduled_at'], 'pe_seq_due_idx');
            $table->index(['conversation_id', 'status'], 'pe_seq_conv_st_idx');
            $table->index(['visitor_id', 'status'], 'pe_seq_vis_st_idx');
            $table->foreign('account_id', 'pe_seq_acct_fk')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('site_id', 'pe_seq_site_fk')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('campaign_id', 'pe_seq_cam_fk')->references('id')->on('proactive_campaigns')->cascadeOnDelete();
            $table->foreign('conversation_id', 'pe_seq_conv_fk')->references('id')->on('conversations')->nullOnDelete();
            $table->foreign('visitor_id', 'pe_seq_vis_fk')->references('id')->on('visitors')->nullOnDelete();
            $table->foreign('social_conversation_id', 'pe_seq_soc_fk')->references('id')->on('social_conversations')->nullOnDelete();
        });

        Schema::create('proactive_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('site_id');
            $table->uuid('campaign_id');
            $table->uuid('sequence_id');
            $table->uuid('conversation_id')->nullable();
            $table->uuid('visitor_id')->nullable();
            $table->uuid('agent_id')->nullable();
            $table->uuid('workflow_id')->nullable();
            $table->uuid('message_id')->nullable();
            $table->uuid('social_message_id')->nullable();
            $table->string('channel', 32);
            $table->string('status', 24)->default('scheduled');
            $table->unsignedTinyInteger('step')->default(1);
            $table->longText('content')->nullable();
            $table->text('decision_reason')->nullable();
            $table->json('evidence')->nullable();
            $table->timestamp('scheduled_at');
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('failure_code', 64)->nullable();
            $table->text('failure_details')->nullable();
            $table->char('idempotency_key', 64);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'idempotency_key'], 'pe_msg_site_idem_uq');
            $table->index(['status', 'scheduled_at'], 'pe_msg_due_idx');
            $table->index(['site_id', 'channel', 'status'], 'pe_msg_site_channel_idx');
            $table->index(['agent_id', 'status'], 'pe_msg_agent_st_idx');
            $table->index(['workflow_id', 'status'], 'pe_msg_workflow_st_idx');
            $table->index(['conversation_id', 'status'], 'pe_msg_conv_st_idx');
            $table->index(['visitor_id', 'status'], 'pe_msg_vis_st_idx');
            $table->foreign('account_id', 'pe_msg_acct_fk')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('site_id', 'pe_msg_site_fk')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('campaign_id', 'pe_msg_cam_fk')->references('id')->on('proactive_campaigns')->cascadeOnDelete();
            $table->foreign('sequence_id', 'pe_msg_seq_fk')->references('id')->on('proactive_sequences')->cascadeOnDelete();
            $table->foreign('conversation_id', 'pe_msg_conv_fk')->references('id')->on('conversations')->nullOnDelete();
            $table->foreign('visitor_id', 'pe_msg_vis_fk')->references('id')->on('visitors')->nullOnDelete();
            $table->foreign('agent_id', 'pe_msg_agent_fk')->references('id')->on('mcp_agents')->nullOnDelete();
            $table->foreign('workflow_id', 'pe_msg_wf_fk')->references('id')->on('mcp_workflows')->nullOnDelete();
            $table->foreign('message_id', 'pe_msg_chat_fk')->references('id')->on('messages')->nullOnDelete();
            $table->foreign('social_message_id', 'pe_msg_social_fk')->references('id')->on('social_messages')->nullOnDelete();
        });

        Schema::create('proactive_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('message_id');
            $table->string('channel', 32);
            $table->string('provider', 32)->nullable();
            $table->string('status', 24)->default('pending');
            $table->string('external_message_id')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_details')->nullable();
            $table->json('provider_response')->nullable();
            $table->char('idempotency_key', 64);
            $table->timestamps();

            $table->unique(['message_id', 'idempotency_key'], 'pe_del_msg_idem_uq');
            $table->index(['status', 'created_at'], 'pe_del_st_created_idx');
            $table->foreign('message_id', 'pe_del_msg_fk')->references('id')->on('proactive_messages')->cascadeOnDelete();
        });

        Schema::create('proactive_outcomes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('site_id');
            $table->uuid('campaign_id');
            $table->uuid('sequence_id');
            $table->uuid('message_id')->nullable();
            $table->uuid('analytics_event_id')->nullable();
            $table->string('outcome_type', 64);
            $table->string('attribution_type', 24)->default('assisted');
            $table->decimal('value', 14, 4)->nullable();
            $table->char('currency', 3)->nullable();
            $table->timestamp('occurred_at');
            $table->char('idempotency_key', 64);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'idempotency_key'], 'pe_out_site_idem_uq');
            $table->index(['campaign_id', 'occurred_at'], 'pe_out_cam_time_idx');
            $table->foreign('account_id', 'pe_out_acct_fk')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('site_id', 'pe_out_site_fk')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('campaign_id', 'pe_out_cam_fk')->references('id')->on('proactive_campaigns')->cascadeOnDelete();
            $table->foreign('sequence_id', 'pe_out_seq_fk')->references('id')->on('proactive_sequences')->cascadeOnDelete();
            $table->foreign('message_id', 'pe_out_msg_fk')->references('id')->on('proactive_messages')->nullOnDelete();
            $table->foreign('analytics_event_id', 'pe_out_evt_fk')->references('id')->on('resource_events')->nullOnDelete();
        });

        Schema::create('proactive_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('site_id');
            $table->uuid('campaign_id')->nullable();
            $table->uuid('sequence_id')->nullable();
            $table->uuid('message_id')->nullable();
            $table->uuid('actor_id')->nullable();
            $table->string('actor_type', 32)->default('system');
            $table->string('action', 64);
            $table->text('reason')->nullable();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['site_id', 'created_at'], 'pe_aud_site_time_idx');
            $table->index(['message_id', 'created_at'], 'pe_aud_msg_time_idx');
            $table->foreign('account_id', 'pe_aud_acct_fk')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('site_id', 'pe_aud_site_fk')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('campaign_id', 'pe_aud_cam_fk')->references('id')->on('proactive_campaigns')->nullOnDelete();
            $table->foreign('sequence_id', 'pe_aud_seq_fk')->references('id')->on('proactive_sequences')->nullOnDelete();
            $table->foreign('message_id', 'pe_aud_msg_fk')->references('id')->on('proactive_messages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proactive_audit_logs');
        Schema::dropIfExists('proactive_outcomes');
        Schema::dropIfExists('proactive_deliveries');
        Schema::dropIfExists('proactive_messages');
        Schema::dropIfExists('proactive_sequences');
        Schema::dropIfExists('proactive_triggers');
        Schema::dropIfExists('proactive_campaigns');
    }
};
