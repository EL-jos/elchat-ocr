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
        Schema::create('rag_evaluation_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('site_id');

            $table->string('status')->default('running'); // running, completed, failed

            $table->integer('total_queries')->default(0);

            $table->float('overall_score')->nullable();
            $table->float('retrieval_score')->nullable();
            $table->float('answer_quality_score')->nullable();
            $table->float('groundedness_score')->nullable();
            $table->float('ranking_score')->nullable();
            $table->float('generation_score')->nullable();
            $table->float('hallucination_rate')->nullable();

            $table->json('metrics_breakdown')->nullable(); // FULL TRACE

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
        Schema::dropIfExists('rag_evaluation_runs');
    }
};
