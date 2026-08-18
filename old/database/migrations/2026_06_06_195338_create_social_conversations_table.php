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
        Schema::create('social_conversations', function (Blueprint $table) {

            $table->uuid('id')->primary();
            $table->uuid('site_id');
            $table->uuid('social_account_id');
            $table->string('provider', 50);
            $table->string('external_user_id')->index();
            $table->string('external_username')->nullable();
            $table->string('external_display_name')->nullable();
            // Type de contexte : 'inbox' | 'feed_comment' | 'feed_reply'
            $table->string('context_type')->nullable();
            // ID du contexte : post_id, comment_id, ou null pour inbox
            $table->string('context_id')->nullable();
            $table->string('source_object_id')->nullable();
            /*
             * YouTube:
             * video_id
             *
             * Facebook:
             * post_id
             *
             * Instagram:
             * media_id
             *
             * TikTok:
             * video_id
             */
            $table->json('metadata')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index([
                'provider',
                'external_user_id'
            ]);

            // ✅ Unicité réelle de la conversation selon les règles métier
            $table->unique(
                ['social_account_id', 'provider', 'external_user_id', 'context_type'],
                'unique_conversation_context'
            );

            $table->foreign('social_account_id')
                ->references('id')
                ->on('social_accounts')
                ->cascadeOnDelete();

            $table->foreign('site_id')->references('id')->on('sites')
                ->onDelete('cascade')->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_conversations');
    }
};
