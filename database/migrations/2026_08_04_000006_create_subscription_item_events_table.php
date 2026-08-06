<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_item_events', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('subscription_item_id', 36);

            $table->enum('event_type', [
                'activated', 'deactivation_requested', 'deactivated',
                'tier_changed', 'price_changed', 'trial_started', 'trial_converted',
            ]);

            $table->json('previous_state')->nullable();
            $table->json('new_state')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->foreign('subscription_item_id')->references('id')->on('subscription_items')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_item_events');
    }
};
