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
        Schema::create('social_reply_queues', function (Blueprint $table) {

            $table->uuid('id')->primary();
            $table->uuid('social_message_id');
            $table->enum('status', [
                'pending',
                'approved',
                'rejected',
                'published',
                'failed',
                'processing'
            ])->default('pending');
            $table->unsignedInteger('attempts')
                ->default(0);
            $table->text('failure_reason')
                ->nullable();
            $table->timestamp('approved_at')
                ->nullable();
            $table->timestamp('published_at')
                ->nullable();
            $table->timestamps();

            $table->foreign('social_message_id')
                ->references('id')
                ->on('social_messages')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_reply_queues');
    }
};
