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
        Schema::table('mcp_pending_actions', function (Blueprint $table) {
            // 🆕 Mémorise QUEL agent était actif au moment de la demande, pour que
            // la reprise après confirmation respecte le même périmètre de
            // compétences (sinon un outil "débloqué" par erreur au moment du oui/non).
            $table->uuid('agent_id')->nullable()->after('confirm_actor');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mcp_pending_actions', function (Blueprint $table) {
            //
        });
    }
};
