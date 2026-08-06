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
        Schema::table('mcp_agents', function (Blueprint $table) {
            // null = hérite toutes les recettes du site (comportement actuel, rétrocompatible)
            // [] = aucune recette assignée   |   [id, id...] = uniquement celles-ci
            $table->json('workflow_ids')->nullable()->after('skills');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mcp_agents', function (Blueprint $table) {
            //
        });
    }
};
