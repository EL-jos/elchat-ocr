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
        Schema::create('social_messages', function (Blueprint $table) {

            $table->uuid('id')->primary();
            $table->uuid('social_conversation_id');
            $table->string('provider', 50);
            $table->string('external_message_id')
                ->nullable()
                ->index();

            $table->enum('direction', [
                'incoming',
                'outgoing'
            ]);
            $table->longText('content');
            $table->string('message_type')
                ->default('text');
            /*
             * text
             * image
             * video
             * document
             */
            $table->boolean('generated_by_ai')
                ->default(false);
            $table->decimal(
                'confidence_score',
                5,
                2
            )->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('published_at')
                ->nullable();
            $table->timestamps();

            $table->index([
                'social_conversation_id',
                'direction'
            ]);

            $table->foreign('social_conversation_id')
                ->references('id')
                ->on('social_conversations')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_messages');
    }
};
