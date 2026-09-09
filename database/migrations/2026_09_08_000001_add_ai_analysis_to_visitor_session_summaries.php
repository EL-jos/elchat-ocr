<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_session_summaries', function (Blueprint $table) {
            $table->string('ai_status', 20)->default('pending')->after('analysis_version');
            $table->string('ai_model', 128)->nullable()->after('ai_status');
            $table->json('ai_analysis')->nullable()->after('ai_model');
            $table->timestamp('ai_generated_at')->nullable()->after('ai_analysis');
            $table->text('ai_error')->nullable()->after('ai_generated_at');
            $table->unsignedInteger('ai_input_tokens')->nullable()->after('ai_error');
            $table->unsignedInteger('ai_output_tokens')->nullable()->after('ai_input_tokens');

            $table->index(['site_id', 'ai_status', 'ai_generated_at'], 'visitor_summaries_ai_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('visitor_session_summaries', function (Blueprint $table) {
            $table->dropIndex('visitor_summaries_ai_status_idx');
            $table->dropColumn([
                'ai_status', 'ai_model', 'ai_analysis', 'ai_generated_at',
                'ai_error', 'ai_input_tokens', 'ai_output_tokens',
            ]);
        });
    }
};
