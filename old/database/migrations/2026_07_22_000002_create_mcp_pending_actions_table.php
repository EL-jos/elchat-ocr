<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Action MCP en attente de confirmation (visiteur OU admin, selon
 * mcp_permissions.confirm_actor). Remplace le passage de contexte brut
 * client -> serveur de la v2 : plus sûr (le client ne peut pas falsifier
 * les paramètres d'une action avant confirmation) et supporte la
 * confirmation asynchrone par un agent back-office.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_pending_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('site_id');
            $table->uuid('conversation_id');
            $table->string('connector_slug');
            $table->string('tool_name');
            $table->json('params');
            $table->enum('confirm_actor', ['visitor', 'admin']);
            $table->string('tool_call_id');
            $table->json('messages_snapshot'); // historique OpenAI nécessaire pour reprendre la boucle LLM
            $table->enum('status', ['pending', 'approved', 'rejected', 'expired'])->default('pending');
            $table->uuid('resolved_by_user_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'confirm_actor', 'status']);

            $table->foreign('site_id')->references('id')->on('sites')
                ->onDelete('cascade')->onUpdate('cascade');

            $table->foreign('conversation_id')->references('id')->on('conversations')
                ->onDelete('cascade')->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_pending_actions');
    }
};
