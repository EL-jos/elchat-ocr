<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_prices', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('module_tier_id', 36);
            $table->enum('billing_cycle', ['monthly', 'yearly']);
            $table->unsignedInteger('price_eur'); // en CENTIMES — jamais de float, jamais codé en dur ailleurs
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('module_tier_id')->references('id')->on('module_tiers')->onDelete('cascade');
            $table->unique(['module_tier_id', 'billing_cycle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_prices');
    }
};
