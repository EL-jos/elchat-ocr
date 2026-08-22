<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitor_session_replay_chunks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('site_id');
            $table->uuid('visitor_session_id');
            $table->unsignedInteger('chunk_index');
            $table->string('format', 32)->default('rrweb-json-gzip-base64');
            $table->string('rrweb_version', 16)->default('2.0.0');
            $table->unsignedInteger('event_count');
            $table->unsignedBigInteger('payload_bytes');
            $table->char('payload_hash', 64);
            $table->timestamp('first_event_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->longText('payload');
            $table->timestamps();

            $table->unique(['site_id', 'visitor_session_id', 'chunk_index'], 'visitor_replay_chunk_unique');
            $table->index(['site_id', 'visitor_session_id', 'first_event_at'], 'visitor_replay_chunk_lookup_idx');
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('visitor_session_id')->references('id')->on('visitor_sessions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_session_replay_chunks');
    }
};
