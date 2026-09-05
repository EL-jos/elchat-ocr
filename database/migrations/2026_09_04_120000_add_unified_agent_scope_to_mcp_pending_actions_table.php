<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_pending_actions', function (Blueprint $table) {
            // Les anciennes actions restent explicitement legacy. Les actions
            // créées par l'orchestrateur unifié conservent le périmètre
            // multi-agent original pour une reprise sûre après confirmation.
            $table->string('orchestration_mode')->default('legacy')->after('agent_id');
            $table->json('agent_scope_snapshot')->nullable()->after('orchestration_mode');
        });
    }

    public function down(): void
    {
        Schema::table('mcp_pending_actions', function (Blueprint $table) {
            $table->dropColumn(['agent_scope_snapshot', 'orchestration_mode']);
        });
    }
};
