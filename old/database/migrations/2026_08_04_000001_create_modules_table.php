<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('slug')->unique();                 // 'core','community','business','agentics','agency'
            $table->string('name');
            $table->text('description')->nullable();          // usage interne
            $table->text('marketing_description')->nullable(); // texte affiché côté App Store

            $table->string('icon')->nullable();

            $table->boolean('is_core')->default(false);        // true uniquement pour 'core' — jamais désactivable
            $table->boolean('requires_tier')->default(true);   // false pour Core (tier unique implicite)
            $table->enum('billing_type', ['subscription', 'contact_sales'])->default('subscription');
            $table->boolean('included_in_trial')->default(true); // false pour Agency

            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
