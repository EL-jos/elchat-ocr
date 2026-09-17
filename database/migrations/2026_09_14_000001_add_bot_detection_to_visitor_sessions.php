<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_sessions', function (Blueprint $table) {
            $table->json('client_signals')->nullable()->after('last_seen_at');
            $table->unsignedTinyInteger('bot_score')->nullable()->after('client_signals');
            $table->timestamp('bot_score_computed_at')->nullable()->after('bot_score');
            $table->index(['site_id', 'bot_score', 'started_at'], 'visitor_sessions_bot_score_idx');
        });
    }

    public function down(): void
    {
        Schema::table('visitor_sessions', function (Blueprint $table) {
            $table->dropIndex('visitor_sessions_bot_score_idx');
            $table->dropColumn(['client_signals', 'bot_score', 'bot_score_computed_at']);
        });
    }
};
