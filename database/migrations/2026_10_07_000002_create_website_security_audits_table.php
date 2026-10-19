<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_security_audits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('site_id');
            $table->uuid('agent_id')->nullable();
            $table->string('trigger', 24)->default('manual');
            $table->string('status', 24)->default('queued');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('phase', 32)->default('queued');
            $table->string('progress_message', 255)->nullable();
            $table->json('configuration_snapshot');
            $table->json('scope_report')->nullable();
            $table->json('inventory')->nullable();
            $table->json('findings')->nullable();
            $table->json('comparison')->nullable();
            $table->json('ai_analysis')->nullable();
            $table->json('warnings')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'created_at'], 'wsa_audit_site_created_idx');
            $table->index(['site_id', 'status'], 'wsa_audit_site_status_idx');
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('agent_id')->references('id')->on('mcp_agents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_security_audits');
    }
};
