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
        Schema::create('chatbot_form_fields', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('form_id');
            $table->string('field_key');
            $table->string('label');
            $table->string('field_type');
            $table->string('placeholder')->nullable();
            $table->string('help_text')->nullable();
            $table->boolean('is_required')->default(false);
            $table->integer('position')->default(0);
            $table->json('options')->nullable();
            $table->json('validation')->nullable();
            $table->json('conditional_logic')->nullable();
            $table->timestamps();

            $table->unique(['form_id', 'field_key']);

            $table->foreign('form_id')->references('id')->on('chatbot_forms')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chatbot_form_fields');
    }
};
