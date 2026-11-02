<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devpilot_task_attachments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('task_id');
            $table->uuid('parent_attachment_id')->nullable();
            $table->string('kind', 32);
            $table->string('original_name', 255);
            $table->string('extension', 12);
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('storage_path', 1024);
            $table->longText('extracted_content')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('task_id')->references('id')->on('devpilot_tasks')->cascadeOnDelete();
            $table->foreign('parent_attachment_id')->references('id')->on('devpilot_task_attachments')->cascadeOnDelete();
            $table->index(['task_id', 'kind']);
            $table->index(['task_id', 'sha256']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devpilot_task_attachments');
    }
};
