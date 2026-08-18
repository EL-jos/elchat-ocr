<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Panier et wishlist LOCAUX à ELChat. WooCommerce REST v3 n'a pas de notion
 * de panier (c'est un mécanisme de session côté navigateur, pas exploitable
 * depuis un backend de bot). On ne touche WooCommerce qu'au moment du
 * checkout, en créant une vraie commande 'pending'.
 *
 * owner_type/owner_id pointent vers un visiteur anonyme OU un user identifié
 * (même convention que ActorContext), pour que le panier survive si le
 * visiteur est transformé en User en cours de conversation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_carts', function (Blueprint $table) {
            $table->id();
            $table->uuid('site_id');
            $table->enum('owner_type', ['visitor', 'user']);
            $table->uuid('owner_id');
            $table->json('items')->nullable(); // [{product_id, variation_id, quantity, name, price}]
            $table->string('coupon_code')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'owner_type', 'owner_id']);

            $table->foreign('site_id')->references('id')->on('sites')
                ->onDelete('cascade')->onUpdate('cascade');
        });

        Schema::create('mcp_wishlists', function (Blueprint $table) {
            $table->id();
            $table->uuid('site_id');
            $table->enum('owner_type', ['visitor', 'user']);
            $table->uuid('owner_id');
            $table->json('items')->nullable(); // [{product_id, variation_id}]
            $table->timestamps();

            $table->unique(['site_id', 'owner_type', 'owner_id']);
            $table->foreign('site_id')->references('id')->on('sites')
                ->onDelete('cascade')->onUpdate('cascade');
        });

        Schema::create('mcp_customer_links', function (Blueprint $table) {
            $table->id();
            $table->uuid('site_id');
            $table->uuid('user_id');
            $table->string('connector_slug');
            $table->string('external_customer_id'); // id client WooCommerce
            $table->timestamps();

            $table->unique(['site_id', 'user_id', 'connector_slug']);

            $table->foreign('site_id')->references('id')->on('sites')
                ->onDelete('cascade')->onUpdate('cascade');

            $table->foreign('user_id')->references('id')->on('users')
                ->onDelete('cascade')->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_customer_links');
        Schema::dropIfExists('mcp_wishlists');
        Schema::dropIfExists('mcp_carts');
    }
};
