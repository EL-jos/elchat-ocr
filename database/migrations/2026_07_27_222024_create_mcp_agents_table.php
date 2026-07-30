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
        Schema::create('mcp_agents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('site_id');
            $table->string('name');
            $table->text('objective')->nullable();
            $table->string('tone')->default('professional'); // professional|friendly|concise|enthusiastic|custom
            $table->text('custom_tone_instructions')->nullable();
            $table->json('skills')->nullable(); // ["crm.create_opportunity", "woocommerce__cancel_order", ...]
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false); // un seul par site pour l'instant
            $table->timestamps();

            $table->foreign('site_id')->references('id')->on('sites')
                ->onDelete('cascade')->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mcp_agents');
    }
};
