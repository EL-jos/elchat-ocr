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
        Schema::create('chat_form_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('site_id');
            $table->uuid('message_id');
            $table->string('form_id');
            $table->json('values');
            $table->timestamps();

            $table->foreign('site_id')->references('id')->on('sites')
                ->cascadeOnUpdate()->cascadeOnDelete();

            $table->foreign('message_id')->references('id')->on('messages')
                ->cascadeOnUpdate()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_form_submissions');
    }
};
