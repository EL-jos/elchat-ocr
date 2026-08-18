<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_daily_metrics', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('account_id')->nullable();
            $table->uuid('site_id');
            $table->date('metric_date');
            $table->string('event_type', 64);
            $table->string('source', 64)->nullable();
            $table->string('channel', 32)->nullable();
            $table->uuid('agent_id')->nullable();
            $table->uuid('workflow_id')->nullable();
            $table->string('attribution_type', 20)->nullable();
            $table->char('currency', 3)->nullable();
            $table->char('dimension_key', 64);
            $table->unsignedBigInteger('event_count')->default(0);
            $table->unsignedBigInteger('unique_visitors')->default(0);
            $table->unsignedBigInteger('unique_conversations')->default(0);
            $table->decimal('value_sum', 20, 4)->nullable();
            $table->timestamps();

            $table->unique(
                ['site_id', 'metric_date', 'event_type', 'dimension_key'],
                'analytics_daily_metrics_dimension_unique'
            );
            $table->index(['site_id', 'metric_date', 'event_type'], 'analytics_daily_metrics_lookup_idx');
            $table->index(['site_id', 'agent_id', 'metric_date'], 'analytics_daily_metrics_agent_idx');
            $table->index(['site_id', 'workflow_id', 'metric_date'], 'analytics_daily_metrics_workflow_idx');
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
        });

        Schema::create('analytics_daily_aggregate_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('account_id')->nullable();
            $table->uuid('site_id');
            $table->date('metric_date');
            $table->unsignedBigInteger('raw_event_count')->default(0);
            $table->timestamp('completed_at');
            $table->timestamps();

            $table->unique(['site_id', 'metric_date'], 'analytics_daily_runs_site_date_unique');
            $table->index(['site_id', 'metric_date', 'completed_at'], 'analytics_daily_runs_lookup_idx');
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_daily_aggregate_runs');
        Schema::dropIfExists('analytics_daily_metrics');
    }
};
