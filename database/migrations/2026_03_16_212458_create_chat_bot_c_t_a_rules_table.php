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
        Schema::create('chatbot_cta_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('cta_id');
            $table->string('rule_type');
            $table->string('rule_value');
            $table->timestamps();

            $table->foreign('cta_id')->references('id')->on('chatbot_ctas')
                ->cascadeOnUpdate()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chatbot_cta_rules');
    }
};
