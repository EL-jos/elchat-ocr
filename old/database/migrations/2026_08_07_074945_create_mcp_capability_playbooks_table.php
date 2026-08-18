<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Référentiel ÉDITORIAL (rempli/maintenu par ELChat, jamais par le tenant)
 * des combinaisons de connecteurs à forte valeur ajoutée métier. Distinct
 * de `mcp_capabilities` (qui est 100% défini par l'admin du site) : ici,
 * chaque ligne est une recommandation proactive rédigée à la main, pas un
 * regroupement mécanique d'outils déjà actifs.
 *
 * Sert de base à CapabilityPlaybookEngine::suggestFor(Site $site).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_capability_playbooks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key')->unique(); // ex: 'ecommerce_acquisition_loop'
            $table->string('label');
            $table->text('value_pitch'); // pourquoi ça compte, formulé pour l'admin du site

            // [] ou null = applicable à tous les types de site. Sinon liste
            // exacte de TypeSite::name (ex: ['E-commerce', 'Marketplace']).
            $table->json('applicable_type_sites')->nullable();

            // Connecteurs requis pour que le playbook soit "complet".
            $table->json('connector_slugs');

            // 1 = essentiel, 2 = fort impact, 3 = complément — pondère le score.
            $table->unsignedTinyInteger('priority_tier')->default(2);

            // Steps McpWorkflow pré-remplis, proposés si l'admin accepte la suggestion.
            $table->json('suggested_workflow_steps')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('priority_tier');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_capability_playbooks');
    }
};
