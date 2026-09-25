<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('website_growth_advisor_configurations')) {
            return;
        }

        Schema::create('website_growth_advisor_configurations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('site_id');
            $table->uuid('agent_id');
            $table->unsignedInteger('version')->default(1);
            $table->string('primary_objective', 64)->default('lead_generation');
            $table->json('secondary_objectives')->nullable();
            $table->json('priority_areas')->nullable();
            $table->json('enabled_sources')->nullable();
            $table->string('period_type', 32)->default('last_30_days');
            $table->timestamp('custom_period_from')->nullable();
            $table->timestamp('custom_period_to')->nullable();
            $table->boolean('compare_previous_period')->default(false);
            $table->json('visitor_segments')->nullable();
            $table->string('analysis_depth', 24)->default('standard');
            $table->json('journey_options')->nullable();
            $table->boolean('use_visual_evidence')->default(true);
            $table->string('visual_analysis_mode', 24)->default('targeted');
            $table->unsignedSmallInteger('max_representative_sessions')->default(12);
            $table->string('evidence_requirement', 24)->default('medium');
            $table->boolean('require_cause_analysis')->default(true);
            $table->boolean('require_evidence')->default(true);
            $table->boolean('allow_hypotheses')->default(true);
            $table->boolean('require_uncertainty_disclosure')->default(true);
            $table->string('recommendation_depth', 24)->default('actionable');
            $table->string('implementation_detail', 24)->default('practical');
            $table->json('prioritization_criteria')->nullable();
            $table->boolean('require_measurement_plan')->default(true);
            $table->string('primary_kpi', 64)->nullable();
            $table->json('secondary_kpis')->nullable();
            $table->json('conversation_options')->nullable();
            $table->json('knowledge_options')->nullable();
            $table->string('execution_mode', 24)->default('manual');
            $table->string('schedule_frequency', 24)->nullable();
            $table->string('schedule_time', 5)->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['site_id', 'agent_id'], 'wga_config_site_agent_unique');
            $table->index(['site_id', 'next_run_at'], 'wga_config_schedule_idx');
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('agent_id')->references('id')->on('mcp_agents')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_growth_advisor_configurations');
    }
};
