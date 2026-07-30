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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->uuid('account_id', 36)->index();

            // Référence au plan local
            $table->char('plan_id', 36)->nullable();

            // Données Stripe
            $table->string('stripe_customer_id')->nullable()->index();
            $table->string('stripe_subscription_id')->nullable()->unique()->index();
            $table->string('stripe_price_id')->nullable();

            // Type d'abonnement
            $table->enum('billing_cycle', ['monthly', 'annual'])->default('monthly');

            // Statut (miroir de Stripe + états internes)
            $table->enum('status', [
                'trialing',     // Période d'essai active
                'active',       // Abonnement payant actif
                'past_due',     // Paiement en retard
                'canceled',     // Annulé (mais peut rester actif jusqu'à fin période)
                'unpaid',       // Paiement échoué définitivement
                'incomplete',   // Checkout initié mais pas finalisé
                'paused',       // Mis en pause
            ])->default('trialing');

            // Dates clés
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('ends_at')->nullable();       // Fin effective (après cancel)

            // Devise et montant (pour audit)
            $table->string('currency', 3)->default('eur');
            $table->unsignedInteger('amount')->nullable();  // En centimes

            // Métadonnées
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
            $table->foreign('plan_id')->references('id')->on('plans')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
