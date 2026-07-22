<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogue global des connecteurs disponibles dans la marketplace.
 * Une ligne = un type de connecteur (woocommerce, google_calendar, stripe...).
 * Ajouter un connecteur = une ligne ici + une classe PHP. Rien d'autre à modifier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_connectors', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique(); // ex: 'woocommerce', 'google_calendar'
            $table->string('name');
            $table->string('category'); // e_commerce, calendar, payment, crm, communication...
            $table->string('adapter_class'); // FQCN de la classe qui implémente MCPConnectorInterface
            $table->string('auth_type'); // oauth2, api_key, basic
            $table->json('default_scopes')->nullable();
            $table->json('available_tools')->nullable(); // cache du schema des tools exposés
            $table->boolean('is_active')->default(true); // désactivation globale (kill switch)
            $table->string('icon_url')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_connectors');
    }
};
