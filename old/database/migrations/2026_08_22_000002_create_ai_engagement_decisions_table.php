<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_engagement_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('site_id');
            $table->uuid('visitor_session_id')->nullable();
            $table->uuid('visitor_id')->nullable();
            $table->uuid('source_event_id')->nullable();
            $table->uuid('conversation_id')->nullable();
            $table->uuid('proactive_message_id')->nullable();
            $table->string('decision', 24);
            $table->unsignedTinyInteger('score')->default(0);
            $table->string('intent_level', 20)->nullable();
            $table->string('page_type', 32)->nullable();
            $table->string('strategy', 32)->nullable();
            $table->text('reason')->nullable();
            $table->json('signals')->nullable();
            $table->json('context_snapshot')->nullable();
            $table->char('idempotency_key', 64);
            $table->timestamp('evaluated_at');
            $table->timestamps();

            $table->unique(['site_id', 'idempotency_key'], 'ai_engagement_decisions_site_idem_unique');
            $table->index(['site_id', 'decision', 'evaluated_at'], 'ai_engagement_decisions_site_decision_idx');
            $table->index(['site_id', 'visitor_id', 'evaluated_at'], 'ai_engagement_decisions_site_visitor_idx');
            $table->index(['site_id', 'visitor_session_id', 'evaluated_at'], 'ai_engagement_decisions_site_session_idx');
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('visitor_session_id')->references('id')->on('visitor_sessions')->nullOnDelete();
            $table->foreign('visitor_id')->references('id')->on('visitors')->nullOnDelete();
            $table->foreign('source_event_id')->references('id')->on('resource_events')->nullOnDelete();
            $table->foreign('conversation_id')->references('id')->on('conversations')->nullOnDelete();
            $table->foreign('proactive_message_id')->references('id')->on('proactive_messages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_engagement_decisions');
    }
};
