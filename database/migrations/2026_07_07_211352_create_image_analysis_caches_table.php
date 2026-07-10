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
        // Cache GLOBAL (cross-tenant) : la même image (même hash d'octets) hébergée sur
        // 2 sites différents, ou re-crawlée plus tard, ne paie jamais 2x l'appel au vision model.
        Schema::create('image_analysis_cache', function (Blueprint $table) {
            $table->string('content_hash', 64)->primary();

            $table->text('description')->nullable();
            $table->text('ocr_text')->nullable();
            $table->boolean('is_decorative')->default(false);

            $table->string('model', 100)->nullable();
            $table->unsignedInteger('hits')->default(1);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('image_analysis_caches');
    }
};
