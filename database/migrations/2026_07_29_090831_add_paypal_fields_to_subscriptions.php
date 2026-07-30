<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ajoute le support PayPal aux tables existantes.
     * Stripe reste intact — on étend, on ne modifie pas.
     */
    public function up(): void
    {
        // ── Table subscriptions ───────────────────────────────────────────────
        Schema::table('subscriptions', function (Blueprint $table) {

            // Fournisseur de paiement
            $table->enum('payment_provider', ['stripe', 'paypal'])
                ->default('stripe')
                ->after('stripe_price_id');

            // Identifiants PayPal (null si provider = stripe)
            $table->string('paypal_subscription_id')->nullable()->unique()->index()->after('payment_provider');
            $table->string('paypal_plan_id')->nullable()->after('paypal_subscription_id');
            $table->string('paypal_payer_id')->nullable()->after('paypal_plan_id');
            $table->string('paypal_order_id')->nullable()->after('paypal_payer_id');

            // Les champs stripe_* existants restent, nullable par défaut
            // stripe_customer_id, stripe_subscription_id, stripe_price_id → déjà nullable ✅
        });

        // ── Table plans ───────────────────────────────────────────────────────
        Schema::table('plans', function (Blueprint $table) {
            // Plan IDs PayPal (générés via php artisan paypal:setup-plans)
            $table->string('paypal_plan_monthly')->nullable()->after('stripe_price_annual');
            $table->string('paypal_plan_annual')->nullable()->after('paypal_plan_monthly');
        });

        // ── Table subscription_events ─────────────────────────────────────────
        Schema::table('subscription_events', function (Blueprint $table) {
            $table->enum('provider', ['stripe', 'paypal'])
                ->default('stripe')
                ->after('account_id');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn([
                'payment_provider',
                'paypal_subscription_id',
                'paypal_plan_id',
                'paypal_payer_id',
                'paypal_order_id',
            ]);
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['paypal_plan_monthly', 'paypal_plan_annual']);
        });

        Schema::table('subscription_events', function (Blueprint $table) {
            $table->dropColumn('provider');
        });
    }
};
