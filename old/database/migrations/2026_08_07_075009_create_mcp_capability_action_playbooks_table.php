<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Référentiel éditorial de COMBOS D'ACTIONS précises (tool_names qualifiés,
 * ex: "google_calendar__create_event"), au même connecteur ou à cheval sur
 * plusieurs — distinct de mcp_capability_playbooks qui lui recommande des
 * CONNECTEURS entiers. Une fois acceptée, une ligne ici produit directement
 * une McpCapability (voir CapabilityActionPlaybookEngine::accept).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_capability_action_playbooks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key')->unique(); // ex: 'crossconnector_lead_to_meeting'
            $table->string('label'); // devient le label de la McpCapability créée à l'acceptation
            $table->text('value_pitch');

            $table->json('applicable_type_sites')->nullable(); // [] ou null = universel

            // Noms d'outils qualifiés (ToolSchema::qualifiedName), même
            // connecteur ou plusieurs. Peut inclure des outils pas encore
            // disponibles : CapabilityResolver::providersFor filtre déjà
            // dynamiquement par outils actifs, la capacité se complètera
            // d'elle-même si le connecteur manquant est activé plus tard.
            $table->json('tool_names');

            $table->unsignedTinyInteger('priority_tier')->default(2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('priority_tier');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_capability_action_playbooks');
    }
};
