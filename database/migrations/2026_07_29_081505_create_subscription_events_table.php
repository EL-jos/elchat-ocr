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
        Schema::create('subscription_events', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('subscription_id', 36)->nullable()->index();
            $table->uuid('account_id', 36)->nullable()->index();

            $table->string('stripe_event_id')->unique()->index();  // Idempotence
            $table->string('event_type');                          // ex: customer.subscription.updated
            $table->json('payload');                               // Corps complet de l'événement
            $table->enum('status', ['processed', 'failed', 'ignored'])->default('processed');
            $table->text('error_message')->nullable();

            $table->timestamp('stripe_created_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_events');
    }
};
