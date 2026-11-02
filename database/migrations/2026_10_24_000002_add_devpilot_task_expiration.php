<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devpilot_tasks', function (Blueprint $table): void {
            $table->timestamp('proposal_expires_at')->nullable()->after('approved_at');
            $table->index(['project_id', 'status', 'created_at'], 'devpilot_tasks_project_status_created_idx');
            $table->index(['site_id', 'status', 'created_at'], 'devpilot_tasks_site_status_created_idx');
            $table->index(['status', 'proposal_expires_at'], 'devpilot_tasks_status_proposal_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::table('devpilot_tasks', function (Blueprint $table): void {
            $table->dropIndex('devpilot_tasks_project_status_created_idx');
            $table->dropIndex('devpilot_tasks_site_status_created_idx');
            $table->dropIndex('devpilot_tasks_status_proposal_expiry_idx');
            $table->dropColumn('proposal_expires_at');
        });
    }
};
