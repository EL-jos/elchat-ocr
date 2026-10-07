<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitor_session_replay_video_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id')->nullable();
            $table->uuid('site_id')->nullable();
            $table->uuid('visitor_session_id')->nullable();
            $table->string('status', 24)->default('queued');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('phase', 32)->default('queued');
            $table->string('progress_message', 255)->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->string('storage_disk', 64)->default('local');
            $table->string('storage_path', 512)->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('frame_count')->nullable();
            $table->unsignedTinyInteger('fps')->default(12);
            $table->boolean('skip_inactive')->default(true);
            $table->decimal('playback_speed', 3, 1)->default(1.0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->index(['site_id', 'visitor_session_id', 'created_at'], 'replay_video_site_session_created_idx');
            $table->index(['status', 'updated_at'], 'replay_video_status_updated_idx');
            // Keep a short-lived tombstone when a site/session is deleted so
            // the private file path remains discoverable for immediate purge.
            $table->foreign('account_id')->references('id')->on('accounts')->nullOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->nullOnDelete();
            $table->foreign('visitor_session_id')->references('id')->on('visitor_sessions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_session_replay_video_exports');
    }
};
