<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_security_remediation_proposals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('site_id');
            $table->uuid('audit_id');
            $table->string('finding_id', 96);
            $table->string('finding_fingerprint', 64);
            $table->string('status', 24)->default('proposed');
            $table->string('connector_slug', 48)->default('jira');
            $table->string('tool_name', 48)->default('create_issue');
            $table->json('params');
            $table->uuid('proposed_by');
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->uuid('conversation_id')->nullable();
            $table->json('result')->nullable();
            $table->string('error_code', 96)->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'status', 'created_at'], 'wsa_remed_site_status_idx');
            $table->unique(['audit_id', 'finding_id', 'status'], 'wsa_remed_audit_finding_status_unique');
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('audit_id')->references('id')->on('website_security_audits')->cascadeOnDelete();
            $table->foreign('proposed_by')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('conversation_id')->references('id')->on('conversations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_security_remediation_proposals');
    }
};
