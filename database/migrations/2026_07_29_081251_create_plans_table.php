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
        Schema::create('plans', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('name');                          // "Starter", "Business", etc.
            $table->string('slug')->unique();                // "starter", "business", etc.
            $table->text('description')->nullable();

            // Stripe Price IDs (à remplir après création dans Stripe Dashboard)
            $table->string('stripe_price_monthly')->nullable();
            $table->string('stripe_price_annual')->nullable();

            // Prix en centimes EUR (ex: 2900 = 29€)
            $table->unsignedInteger('price_monthly_eur');    // Prix mensuel (abonnement mensuel)
            $table->unsignedInteger('price_annual_eur');     // Prix mensuel (abonnement annuel)

            // Limites du plan
            $table->unsignedInteger('max_sites');
            $table->unsignedInteger('max_social_networks_per_site');
            $table->unsignedInteger('max_messages_per_month');
            $table->unsignedBigInteger('max_chunks');
            $table->unsignedBigInteger('max_tokens');

            // Fonctionnalités spéciales
            $table->boolean('has_sla')->default(false);
            $table->boolean('has_white_label')->default(false);
            $table->boolean('is_enterprise')->default(false);   // Contact commercial
            $table->boolean('is_active')->default(true);

            // Ordre d'affichage
            $table->unsignedTinyInteger('sort_order')->default(0);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
