<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal d'audit immuable de chaque tentative d'appel MCP.
 * Sert au debug, à la conformité, et à la détection d'abus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('site_id');
            $table->uuid('conversation_id')->nullable();
            $table->string('connector_slug');
            $table->string('tool_name');
            $table->json('input_params')->nullable();      // paramètres envoyés (PII filtrée)
            $table->json('output_summary')->nullable();     // résultat résumé, jamais la réponse brute complète
            $table->enum('permission_mode', ['auto', 'confirm', 'deny']);
            $table->enum('status', ['success', 'denied', 'error', 'awaiting_confirmation', 'timeout']);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error_code')->nullable();
            $table->string('hop_number')->nullable(); // position dans la chaîne multi-hop (1, 2, 3...)
            $table->timestamps();

            $table->index(['site_id', 'created_at']);
            $table->index(['connector_slug', 'tool_name']);

            $table->foreign('site_id')->references('id')->on('sites')->onDelete('cascade');
            $table->foreign('conversation_id')->references('id')->on('conversations')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_audit_logs');
    }
};
