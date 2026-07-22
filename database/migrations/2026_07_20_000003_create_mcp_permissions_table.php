<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Règle de permission par site x connecteur x action.
 * mode: 'auto' (exécution autonome), 'confirm' (validation humaine avant exécution),
 * 'deny' (action bloquée même si le connecteur est actif).
 * Une action non présente dans cette table est refusée par défaut (fail-closed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_permissions', function (Blueprint $table) {
            $table->id();
            $table->uuid('site_id');
            $table->string('connector_slug');
            $table->string('tool_name'); // ex: get_order_status, create_calendar_event
            $table->enum('mode', ['auto', 'confirm', 'deny'])->default('confirm');
            $table->unsignedInteger('daily_call_limit')->nullable(); // garde-fou anti-emballement
            $table->timestamps();

            $table->unique(['site_id', 'connector_slug', 'tool_name']);

            $table->foreign('site_id')->references('id')->on('sites')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_permissions');
    }
};
