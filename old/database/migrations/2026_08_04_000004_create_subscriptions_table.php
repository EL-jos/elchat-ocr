<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->char('id', 36)->primary();

            // OneToOne garanti par UNIQUE — un account = une seule subscription ELChat
            $table->char('account_id', 36)->unique();

            $table->enum('payment_provider', ['paypal', 'stripe'])->default('paypal');
            $table->string('provider_customer_id')->nullable()->index();
            $table->string('provider_subscription_id')->nullable()->unique()->index();

            $table->enum('status', [
                'trialing', 'active', 'past_due', 'canceled', 'incomplete', 'paused',
            ])->default('trialing');

            $table->enum('billing_cycle', ['monthly', 'yearly'])->default('monthly');
            $table->char('currency', 3)->default('EUR'); // figé — pas de conversion

            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
