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
        Schema::create('rag_evaluation_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('run_id');
            $table->uuid('site_id');

            $table->longText('query');

            $table->json('retrieved_chunks');
            $table->json('vector_results');
            $table->json('keyword_results');
            $table->json('hybrid_results');

            $table->json('reranked_chunks');

            $table->longText('llm_answer')->nullable();

            $table->float('retrieval_recall')->nullable();
            $table->float('mrr')->nullable();
            $table->float('ndcg')->nullable();

            $table->float('faithfulness')->nullable();
            $table->float('groundedness')->nullable();
            $table->float('answer_relevance')->nullable();
            $table->timestamps();

            $table->foreign('run_id')->references('id')->on('rag_evaluation_runs')
                ->cascadeOnUpdate()->cascadeOnDelete();

            $table->foreign('site_id')->references('id')->on('sites')
                ->cascadeOnUpdate()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rag_evaluation_results');
    }
};
