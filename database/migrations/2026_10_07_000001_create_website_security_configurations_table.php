<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_security_configurations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('site_id');
            $table->uuid('agent_id');
            $table->unsignedInteger('version')->default(1);
            $table->string('audit_mode', 24)->default('audit');
            $table->string('analysis_depth', 24)->default('standard');
            $table->boolean('ai_interpretation')->default(true);
            $table->json('checks');
            $table->string('execution_mode', 24)->default('manual');
            $table->string('schedule_frequency', 24)->nullable();
            $table->string('schedule_time', 5)->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['site_id', 'agent_id'], 'wsa_config_site_agent_unique');
            $table->index(['is_active', 'execution_mode', 'next_run_at'], 'wsa_config_schedule_idx');
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('agent_id')->references('id')->on('mcp_agents')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_security_configurations');
    }
};
