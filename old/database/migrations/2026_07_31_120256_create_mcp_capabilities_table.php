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
        Schema::create('mcp_capabilities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('site_id');
            $table->string('key');
            $table->string('label');
            $table->json('tool_names'); // ex: ["google_calendar__create_event", "hubspot__create_meeting"]
            $table->timestamps();
            $table->unique(['site_id', 'key']);
            $table->foreign('site_id')->references('id')->on('sites')
                ->onDelete('cascade')->cascadeOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mcp_capabilities');
    }
};
