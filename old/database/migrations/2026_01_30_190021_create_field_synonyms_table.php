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
        Schema::create('field_synonyms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('field_key');
            $table->string('synonym');
            $table->enum('language', ['fr', 'en']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('field_synonyms');
    }
};
