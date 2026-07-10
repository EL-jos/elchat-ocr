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
        Schema::create('page_images', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('page_id');
            $table->uuid('site_id');

            $table->string('url', 2048);
            // Hash de l'URL normalisée -> dédup rapide au sein d'une page (pas besoin de télécharger)
            $table->string('url_hash', 64);
            // Hash sha256 des octets réels de l'image -> dédup globale cross-tenant (rempli après download)
            $table->string('content_hash', 64)->nullable();

            $table->string('alt', 500)->nullable();
            $table->text('context')->nullable(); // texte environnant (pour donner du contexte au vision model)

            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            $table->enum('status', ['pending', 'processing', 'done', 'skipped', 'error'])
                ->default('pending');

            $table->text('description')->nullable(); // description générée par le vision model
            $table->text('ocr_text')->nullable();      // texte extrait de l'image (OCR)
            $table->string('error_message', 500)->nullable();

            $table->uuid('chunk_id')->nullable(); // chunk RAG résultant, une fois indexé

            $table->timestamps();

            $table->unique(['page_id', 'url_hash']);
            $table->index(['site_id', 'status']);
            $table->index('content_hash');

            $table->foreign('page_id')->references('id')->on('pages')->onDelete('cascade');
            $table->foreign('site_id')->references('id')->on('sites')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('page_images');
    }
};
