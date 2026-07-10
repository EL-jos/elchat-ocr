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
        Schema::create('rag_evaluation_queries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('site_id');
            $table->uuid('run_id');

            $table->longText('query');
            $table->json('expected_chunk_ids');

            $table->string('category')->nullable(); // faq, ecommerce, blog
            $table->integer('difficulty')->default(1);
            $table->timestamps();

            $table->foreign('site_id')->references('id')->on('sites')
                ->cascadeOnUpdate()->cascadeOnDelete();

            $table->foreign('run_id')->references('id')->on('rag_evaluation_runs')
                ->cascadeOnUpdate()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rag_evaluation_queries');
    }
};
