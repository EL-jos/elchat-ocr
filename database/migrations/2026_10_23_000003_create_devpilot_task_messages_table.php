<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devpilot_task_messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('task_id');
            $table->string('role', 16);
            $table->string('created_by', 64)->nullable();
            $table->text('content');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('task_id')->references('id')->on('devpilot_tasks')->cascadeOnDelete();
            $table->index(['task_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devpilot_task_messages');
    }
};
