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
        Schema::create('message_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('message_id');

            // 'image' pour l'instant ; enum plutôt que string pour se laisser
            // la porte ouverte (audio, pdf...) sans migration supplémentaire.
            $table->enum('type', ['image'])->default('image');

            $table->string('url', 2048);

            // Même hash que image_analysis_cache : permet de savoir si cette
            // image jointe correspond à une image déjà connue du système
            // (ex: le visiteur a envoyé la photo d'un produit déjà indexé).
            $table->string('content_hash', 64)->nullable();

            $table->text('description')->nullable();
            $table->text('ocr_text')->nullable();

            $table->timestamps();

            $table->index(['message_id']);
            $table->index('content_hash');

            $table->foreign('message_id')->references('id')->on('messages')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
    }
};
