<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // database/migrations/..._create_mcp_workflows_table.php
        Schema::create('mcp_workflows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // null = recette globale proposée par défaut à tous les sites (éditable
            // par un site => se transforme alors en copie propre à ce site, voir
            // MCPWorkflowController::update).
            $table->uuid('site_id')->nullable();
            $table->string('slug');
            $table->string('name');
            $table->text('trigger_description');
            $table->json('steps'); // [{capability, label, optional}]
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('site_id')->references('id')->on('sites')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mcp_workflows');
    }
};
