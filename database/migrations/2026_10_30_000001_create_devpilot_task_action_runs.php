<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devpilot_task_action_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('task_id');
            $table->char('proposal_hash', 64);
            $table->unsignedSmallInteger('action_index');
            $table->char('action_fingerprint', 64);
            $table->string('action_type', 96);
            $table->string('status', 24)->default('pending');
            $table->boolean('replay_safe')->default(false);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->longText('result_encrypted')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('task_id')->references('id')->on('devpilot_tasks')->cascadeOnDelete();
            $table->unique(['task_id', 'proposal_hash', 'action_index'], 'devpilot_action_run_unique');
            $table->index(['task_id', 'proposal_hash', 'status'], 'devpilot_action_run_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devpilot_task_action_runs');
    }
};
