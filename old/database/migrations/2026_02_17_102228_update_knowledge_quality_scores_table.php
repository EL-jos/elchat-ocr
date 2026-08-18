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
        Schema::dropIfExists('knowledge_quality_scores');
        Schema::create('knowledge_quality_scores', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('site_id');
            $table->string('scope_type')->default('global');

            $table->float('coverage_score')->default(0);
            $table->float('integrity_score')->default(0);
            $table->float('retrieval_score')->default(0);
            $table->float('redundancy_score')->default(0);
            $table->float('freshness_score')->default(0);
            $table->float('global_score')->default(0);
            $table->float('data_quality_score')->nullable();
            $table->float('precision')->default(0);

            $table->json('recommendations')->nullable();


            $table->foreign('site_id')->references('id')->on('sites')
                ->cascadeOnUpdate()->cascadeOnDelete();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_quality_scores');
        Schema::create('knowledge_quality_scores', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('site_id');
            $table->string('scope_type')->default('global'); // global | products | pages | faq | blog
            $table->float('coverage_score')->default(0);
            $table->float('data_quality_score')->default(0);
            $table->float('semantic_score')->default(0);
            $table->float('freshness_score')->default(0);
            $table->float('global_score')->default(0);
            $table->timestamps();

            $table->foreign('site_id')->references('id')->on('sites')
                ->cascadeOnUpdate()->cascadeOnDelete();
        });
    }
};
