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
        Schema::create('message_ctas', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('message_id');
            $table->uuid('cta_id');

            $table->integer('position')->default(0);

            // snapshot du CTA au moment de l'affichage
            $table->string('label');
            $table->string('action');
            $table->text('value')->nullable();
            $table->string('style')->nullable();

            $table->timestamps();

            $table->foreign('message_id')
                ->references('id')
                ->on('messages')
                ->cascadeOnDelete();

            $table->foreign('cta_id')
                ->references('id')
                ->on('chatbot_ctas')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('message_ctas');
    }
};
