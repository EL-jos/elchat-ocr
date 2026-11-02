<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devpilot_terminal_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('site_id');
            $table->uuid('project_id');
            $table->uuid('task_id');
            $table->string('status', 24)->default('starting');
            $table->longText('output_encrypted')->nullable();
            $table->longText('pending_input_encrypted')->nullable();
            $table->unsignedBigInteger('input_sequence')->default(0);
            $table->unsignedBigInteger('consumed_input_sequence')->default(0);
            $table->timestamps();

            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('devpilot_projects')->cascadeOnDelete();
            $table->foreign('task_id')->references('id')->on('devpilot_tasks')->cascadeOnDelete();
            $table->index(['task_id', 'status', 'created_at'], 'devpilot_terminal_task_status_idx');
            $table->index(['site_id', 'project_id', 'task_id'], 'devpilot_terminal_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devpilot_terminal_sessions');
    }
};
