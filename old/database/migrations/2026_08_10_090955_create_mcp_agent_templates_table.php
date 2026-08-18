<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogue ELChat (global, PAS scopé par site) de la Banque d'Agents —
 * même logique que mcp_capability_playbooks : rempli/maintenu par ELChat,
 * jamais par le tenant. Installer un template crée un mcp_agents réel pour
 * le site (voir AgentTemplateInstaller).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_agent_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key')->unique(); // ex: 'sales_hunter'
            $table->string('name'); // ex: 'AI Sales Hunter'
            $table->string('category'); // ex: 'sales'
            $table->text('description');
            $table->string('icon_url')->nullable();

            // Slug du module d'abonnement requis pour installer ce template
            // (App\Services\payment\ModuleCatalogService gère la vérification réelle).
            $table->string('required_module_slug')->nullable();

            // Pré-remplissage de l'agent créé à l'installation : objective,
            // tone, skills[] par défaut (capacités "sales-*" existantes +
            // capacités sales_hunter__* du nouveau connecteur interne).
            $table->json('default_config');

            // Slugs de McpWorkflow globaux à proposer au provisionnement
            // (WorkflowProvisioningService) au moment de l'installation.
            $table->json('bootstrap_workflow_slugs')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_agent_templates');
    }
};
