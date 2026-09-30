<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_sessions', function (Blueprint $table) {
            $table->string('bot_classification', 24)->nullable()->after('bot_score');
            $table->json('bot_reasons')->nullable()->after('bot_classification');
            $table->json('bot_features')->nullable()->after('bot_reasons');
            $table->index(['site_id', 'bot_classification', 'started_at'], 'visitor_sessions_bot_classification_idx');
        });
    }

    public function down(): void
    {
        Schema::table('visitor_sessions', function (Blueprint $table) {
            $table->dropIndex('visitor_sessions_bot_classification_idx');
            $table->dropColumn(['bot_classification', 'bot_reasons', 'bot_features']);
        });
    }
};
