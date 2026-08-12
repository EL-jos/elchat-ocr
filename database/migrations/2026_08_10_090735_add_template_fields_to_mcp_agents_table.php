<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extension de mcp_agents (pas de duplication) : un agent peut désormais
 * provenir d'un template de la Banque d'Agents. `template_key` null =
 * agent construit à la main (comportement actuel, inchangé). `agent_type`
 * distingue les agents nécessitant un comportement d'exécution spécialisé
 * (ex: 'sales_hunter' déclenche le pipeline de prospection) des agents
 * génériques pilotés uniquement par skills/workflow_ids.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_agents', function (Blueprint $table) {
            $table->string('template_key')->nullable()->after('id');
            $table->string('agent_type')->nullable()->after('template_key'); // null = générique
        });
    }

    public function down(): void
    {
        Schema::table('mcp_agents', function (Blueprint $table) {
            $table->dropColumn(['template_key', 'agent_type']);
        });
    }
};
