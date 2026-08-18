<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Table technique — cache des plans PayPal créés dynamiquement par palier de montant.
     * PayPal ne supporte pas les lignes multiples dans un abonnement récurrent : on simule
     * un abonnement modulaire en révisant l'abonnement client vers le plan correspondant
     * au montant total agrégé (Core + modules actifs) à chaque changement.
     * On réutilise un plan existant si un montant identique + cycle a déjà été créé.
     */
    public function up(): void
    {
        Schema::create('paypal_plan_cache', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->unsignedInteger('amount_eur'); // centimes
            $table->enum('billing_cycle', ['monthly', 'yearly']);
            $table->string('paypal_product_id');
            $table->string('paypal_plan_id')->unique();
            $table->timestamps();

            $table->unique(['amount_eur', 'billing_cycle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paypal_plan_cache');
    }
};
