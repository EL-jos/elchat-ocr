<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Activation d'un connecteur par un site (tenant) + ses identifiants chiffrés.
 * C'est ici que vit le multi-tenant : chaque site a ses propres tokens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_site_connectors', function (Blueprint $table) {
            $table->id();
            $table->uuid('site_id');
            $table->foreignId('mcp_connector_id')->constrained('mcp_connectors')->cascadeOnDelete();

            // Identifiants chiffrés (voir CredentialVault). Jamais en clair, jamais loggés.
            $table->text('credentials_encrypted')->nullable();

            $table->enum('status', ['pending', 'connected', 'auth_expired', 'revoked', 'error'])
                ->default('pending');

            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->string('last_error_message')->nullable();

            $table->json('settings')->nullable(); // ex: store_url pour WooCommerce, calendar_id pour Calendar
            $table->timestamps();

            $table->unique(['site_id', 'mcp_connector_id']);

            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_site_connectors');
    }
};
