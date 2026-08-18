<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_items', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('subscription_id', 36);
            $table->char('module_id', 36);
            $table->char('module_tier_id', 36)->nullable(); // null si module sans tier (Core)

            $table->unsignedInteger('unit_price_eur'); // snapshot du prix au moment de l'activation (centimes)
            $table->enum('billing_cycle', ['monthly', 'yearly']); // doit matcher subscriptions.billing_cycle

            $table->enum('status', [
                'trialing', 'active', 'pending_cancellation', 'canceled',
            ])->default('active');

            $table->timestamp('activated_at')->nullable();
            $table->timestamp('canceled_at')->nullable(); // date de désactivation demandée par le client
            $table->timestamp('access_ends_at')->nullable(); // fin de période payée — accès coupé à cette date

            $table->timestamps();

            $table->foreign('subscription_id')->references('id')->on('subscriptions')->onDelete('cascade');
            $table->foreign('module_id')->references('id')->on('modules')->onDelete('restrict');
            $table->foreign('module_tier_id')->references('id')->on('module_tiers')->onDelete('restrict');

            $table->index(['subscription_id', 'module_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_items');
    }
};
