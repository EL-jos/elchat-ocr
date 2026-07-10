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
        Schema::create('social_events', function (Blueprint $table) {

            $table->uuid('id')->primary();
            $table->uuid('social_account_id')
                ->nullable();
            $table->string('provider', 50);
            $table->string('event_type');
            /*
             * comment_received
             * message_received
             * reply_published
             * token_refreshed
             * sync_failed
             */
            $table->string('external_event_id')
                ->nullable();
            $table->json('payload');
            $table->json('metadata')
                ->nullable();

            $table->string('processing_status')
                ->default('pending');

            $table->timestamps();

            $table->index([
                'provider',
                'event_type'
            ]);

            $table->index([
                'provider',
                'created_at'
            ]);

            $table->index('processing_status');

            $table->foreign('social_account_id')
                ->references('id')
                ->on('social_accounts')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_events');
    }
};
