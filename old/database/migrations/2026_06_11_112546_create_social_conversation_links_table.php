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
        Schema::create('social_conversation_links', function (Blueprint $table) {

            $table->uuid('id')->primary();
            $table->uuid('social_conversation_id');
            $table->uuid('conversation_id');
            $table->timestamps();

            $table->unique([
                'social_conversation_id'
            ]);

            $table->foreign('social_conversation_id')->references('id')->on('social_conversations')
                ->cascadeOnDelete()->cascadeOnUpdate();

            $table->foreign('conversation_id')->references('id')->on('conversations')
                ->cascadeOnDelete()->cascadeOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_conversation_links');
    }
};
