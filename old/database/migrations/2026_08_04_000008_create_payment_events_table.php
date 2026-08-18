<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_events', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('subscription_id', 36)->nullable();
            $table->char('account_id', 36)->nullable();

            $table->enum('provider', ['paypal', 'stripe']);
            $table->string('provider_event_id')->unique(); // idempotence

            $table->string('event_type', 100);
            $table->json('payload');

            $table->enum('status', ['processed', 'failed', 'ignored'])->default('processed');
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->foreign('subscription_id')->references('id')->on('subscriptions')->onDelete('set null');
            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
    }
};
