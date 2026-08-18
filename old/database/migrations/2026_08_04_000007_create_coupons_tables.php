<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('code', 64)->unique();
            $table->enum('type', ['percentage', 'fixed']);
            $table->unsignedInteger('value'); // pourcentage (1-100) OU centimes si fixed

            $table->enum('duration_type', ['once', 'repeating', 'forever'])->default('once');
            $table->unsignedSmallInteger('duration_months')->nullable(); // si repeating

            $table->json('applies_to_modules')->nullable(); // ['community','business'] ou null = tous

            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('redeemed_count')->default(0);

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });

        Schema::create('subscription_coupons', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('subscription_id', 36);
            $table->char('coupon_id', 36);

            $table->string('provider_coupon_ref')->nullable(); // id chez PayPal/Stripe si sync natif

            $table->timestamp('applied_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->foreign('subscription_id')->references('id')->on('subscriptions')->onDelete('cascade');
            $table->foreign('coupon_id')->references('id')->on('coupons')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_coupons');
        Schema::dropIfExists('coupons');
    }
};
