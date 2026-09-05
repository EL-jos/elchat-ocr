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
        Schema::create('chatbot_ctas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('site_id');
            $table->string('label');
            $table->string('action');
            $table->longText('value')->nullable();
            $table->integer('position')->default(0);
            $table->integer('max_display')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('style')->default('primary');

            $table->timestamps();

            $table->foreign('site_id')->references('id')->on('sites')
                ->cascadeOnUpdate()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chatbot_ctas');
    }
};
