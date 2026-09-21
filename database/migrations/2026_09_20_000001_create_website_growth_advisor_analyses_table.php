<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_growth_advisor_analyses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('site_id');
            $table->uuid('agent_id')->nullable();
            $table->timestamp('period_from');
            $table->timestamp('period_to');
            $table->boolean('include_external')->default(true);
            $table->string('status', 24)->default('queued');
            $table->json('source_status')->nullable();
            $table->json('data_snapshot')->nullable();
            $table->json('result')->nullable();
            $table->string('model', 191)->nullable();
            $table->unsignedInteger('representative_sessions_count')->default(0);
            $table->unsignedInteger('visual_moments_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'created_at'], 'wga_site_created_idx');
            $table->index(['site_id', 'status'], 'wga_site_status_idx');
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('agent_id')->references('id')->on('mcp_agents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_growth_advisor_analyses');
    }
};
