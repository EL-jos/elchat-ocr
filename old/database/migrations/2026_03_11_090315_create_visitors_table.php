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
        Schema::create('visitors', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('site_id');

            // UUID stocké dans le navigateur
            $table->uuid('uuid');

            // Si le visiteur devient un user
            $table->uuid('user_id')->nullable();

            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('device')->nullable();

            $table->timestamps();

            $table->index(['site_id', 'uuid']);

            $table->foreign('site_id')->references('id')->on('sites')
                ->onDelete('cascade')->onUpdate('cascade');

            $table->foreign('user_id')->references('id')->on('users')
                ->nullOnDelete()->cascadeOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visitors');
    }
};
