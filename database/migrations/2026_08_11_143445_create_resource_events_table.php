<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('site_id');
            $table->uuid('conversation_id');
            $table->uuid('message_id')->nullable();

            // 'cta' | 'product' | 'page' | 'document' | 'image' — jamais > 20 chars
            $table->string('resource_type', 20);

            // id du CTA (uuid = 36) ou url d'une entity — réduit à 191
            // (limite historique InnoDB utf8mb4 pour une clé simple, marge suffisante)
            $table->string('resource_id', 191)->nullable();

            // 'impression' | 'click' | 'conversion' — jamais > 20 chars
            $table->string('event_type', 20);

            // action du CTA (open_url, navigate...) — pas besoin d'indexer, mais on borne quand même
            $table->string('action', 30)->nullable();
            $table->string('label', 255)->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['site_id', 'resource_type', 'event_type', 'created_at'], 'resource_events_analytics_idx');
            $table->index(['site_id', 'resource_type', 'resource_id', 'event_type'], 'resource_events_resource_idx');
            $table->index('conversation_id');

            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            $table->foreign('message_id')->references('id')->on('messages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_events');
    }
};