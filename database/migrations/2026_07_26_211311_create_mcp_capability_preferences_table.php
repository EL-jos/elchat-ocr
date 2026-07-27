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
        // database/migrations/..._create_mcp_capability_preferences_table.php
        Schema::create('mcp_capability_preferences', function (Blueprint $table) {
            $table->id();
            $table->uuid('site_id');
            $table->string('capability');
            $table->string('connector_slug');
            $table->timestamps();
            $table->unique(['site_id', 'capability']);

            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mcp_capability_preferences');
    }
};
