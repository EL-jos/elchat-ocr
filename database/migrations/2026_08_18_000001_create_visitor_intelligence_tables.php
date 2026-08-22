<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitor_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('site_id');
            $table->uuid('visitor_id')->nullable();
            $table->string('session_key', 100);
            $table->timestamp('started_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('ended_at')->nullable();
            $table->text('entry_url')->nullable();
            $table->text('exit_url')->nullable();
            $table->string('device', 32)->nullable();
            $table->string('source', 64)->nullable();
            $table->boolean('is_new_visitor')->default(true);
            $table->unsignedInteger('page_count')->default(0);
            $table->unsignedInteger('unique_page_count')->default(0);
            $table->unsignedInteger('event_count')->default(0);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->boolean('has_widget_interaction')->default(false);
            $table->string('intent_level', 20)->nullable();
            $table->string('outcome', 64)->nullable();
            $table->boolean('converted')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'session_key'], 'visitor_sessions_site_key_unique');
            $table->index(['site_id', 'started_at'], 'visitor_sessions_site_time_idx');
            $table->index(['site_id', 'visitor_id', 'started_at'], 'visitor_sessions_visitor_time_idx');
            $table->index(['site_id', 'intent_level', 'started_at'], 'visitor_sessions_intent_idx');
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('visitor_id')->references('id')->on('visitors')->nullOnDelete();
        });

        Schema::create('visitor_session_summaries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('site_id');
            $table->uuid('visitor_session_id');
            $table->text('summary')->nullable();
            $table->string('intent_level', 20)->nullable();
            $table->string('probable_goal', 255)->nullable();
            $table->string('probable_outcome', 64)->nullable();
            $table->json('friction_points')->nullable();
            $table->json('purchase_signals')->nullable();
            $table->json('unresolved_questions')->nullable();
            $table->json('important_pages')->nullable();
            $table->json('important_ctas')->nullable();
            $table->json('abandonment_signals')->nullable();
            $table->json('evidence')->nullable();
            $table->string('analysis_version', 32)->default('deterministic-1');
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique('visitor_session_id', 'visitor_summaries_session_unique');
            $table->index(['site_id', 'generated_at'], 'visitor_summaries_site_time_idx');
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('visitor_session_id')->references('id')->on('visitor_sessions')->cascadeOnDelete();
        });

        Schema::create('visitor_opportunities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('site_id');
            $table->uuid('visitor_session_id')->nullable();
            $table->uuid('visitor_id')->nullable();
            $table->string('type', 64);
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->json('evidence')->nullable();
            $table->string('impact', 20)->default('medium');
            $table->string('priority', 20)->default('medium');
            $table->decimal('confidence', 5, 2)->nullable();
            $table->json('recommendations')->nullable();
            $table->json('actions')->nullable();
            $table->string('status', 24)->default('open');
            $table->timestamp('detected_at');
            $table->string('deduplication_key', 191);
            $table->timestamps();

            $table->unique(['site_id', 'deduplication_key'], 'visitor_opportunities_site_dedupe_unique');
            $table->index(['site_id', 'status', 'priority'], 'visitor_opportunities_site_status_idx');
            $table->index(['site_id', 'detected_at'], 'visitor_opportunities_site_time_idx');
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('visitor_session_id')->references('id')->on('visitor_sessions')->nullOnDelete();
            $table->foreign('visitor_id')->references('id')->on('visitors')->nullOnDelete();
        });

        Schema::create('visitor_intelligence_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('site_id');
            $table->uuid('created_by')->nullable();
            $table->string('name', 255);
            $table->string('trigger', 64);
            $table->json('conditions')->nullable();
            $table->json('action');
            $table->string('frequency', 32)->default('event');
            $table->json('limits')->nullable();
            $table->unsignedInteger('cooldown_seconds')->default(3600);
            $table->boolean('approval_required')->default(true);
            $table->string('channel', 32)->nullable();
            $table->json('audience')->nullable();
            $table->json('schedule')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'is_active', 'trigger'], 'visitor_rules_site_trigger_idx');
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('visitor_intelligence_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('site_id');
            $table->uuid('visitor_session_id')->nullable();
            $table->uuid('opportunity_id')->nullable();
            $table->uuid('rule_id')->nullable();
            $table->string('action_type', 64);
            $table->string('source', 64)->default('visitor_intelligence');
            $table->string('status', 24)->default('pending');
            $table->boolean('approval_required')->default(true);
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->string('idempotency_key', 191);
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'idempotency_key'], 'visitor_actions_site_idem_unique');
            $table->index(['site_id', 'status', 'created_at'], 'visitor_actions_site_status_idx');
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('visitor_session_id')->references('id')->on('visitor_sessions')->nullOnDelete();
            $table->foreign('opportunity_id')->references('id')->on('visitor_opportunities')->nullOnDelete();
            $table->foreign('rule_id')->references('id')->on('visitor_intelligence_rules')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_intelligence_actions');
        Schema::dropIfExists('visitor_intelligence_rules');
        Schema::dropIfExists('visitor_opportunities');
        Schema::dropIfExists('visitor_session_summaries');
        Schema::dropIfExists('visitor_sessions');
    }
};
