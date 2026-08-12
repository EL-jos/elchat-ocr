<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pas de journal parallèle (mcp_prospect_activities) : mcp_audit_logs
 * trace déjà chaque appel d'outil avec tout le contexte utile. Une simple
 * colonne nullable suffit à reconstituer la chronologie complète d'un
 * prospect (prospect_discovered, message_sent, lead_created... sont déjà
 * des tool_name/status existants ou à ajouter côté SalesHunterConnector).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_audit_logs', function (Blueprint $table) {
            $table->uuid('prospect_id')->nullable()->after('conversation_id');
            $table->index('prospect_id');
        });
    }

    public function down(): void
    {
        Schema::table('mcp_audit_logs', function (Blueprint $table) {
            $table->dropColumn('prospect_id');
        });
    }
};
