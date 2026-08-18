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
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('site_id');
            $table->string('product_name')->nullable();
            $table->string('product_reference')->nullable();
            $table->string('product_type')->nullable();
            $table->string('product_category')->nullable();
            $table->longText('description')->nullable();
            $table->string('price')->nullable();
            $table->string('currency')->nullable();
            $table->string('price_min')->nullable();
            $table->string('price_max')->nullable();
            $table->string('discount_price')->nullable();
            $table->string('tax_rate')->nullable();
            $table->longText('short_description')->nullable();
            $table->longText('features')->nullable();
            $table->string('brand')->nullable();
            $table->longText('tags')->nullable();
            $table->longText('keywords')->nullable();
            $table->string('stock_status')->nullable();
            $table->string('stock_quantity')->nullable();
            $table->string('weight')->nullable();
            $table->longText('dimensions')->nullable();
            $table->longText('colors')->nullable();
            $table->longText('materials')->nullable();
            $table->string('availability')->nullable();
            $table->longText('image_url')->nullable();
            $table->longText('product_url')->nullable();
            $table->longText('gallery_urls')->nullable();
            $table->longText('video_url')->nullable();
            $table->string('status')->nullable();
            $table->string('language')->nullable();
            $table->string('visibility')->nullable();
            $table->string('created_in_website_at')->nullable();
            $table->timestamps();

            $table->index('product_reference');
            $table->index('product_name');
            $table->index('product_category');

            $table->foreign('site_id')->references('id')->on('sites')
            ->cascadeOnUpdate()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
