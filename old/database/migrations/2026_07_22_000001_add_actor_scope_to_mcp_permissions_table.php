<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_permissions', function (Blueprint $table) {
            // Qui peut APPELER l'outil. Défaut 'visitor' pour ne pas casser
            // les permissions déjà configurées et testées (get_order_status...).
            // ⚠️ Passez explicitement en 'admin' les actions sensibles lors de
            // la revue (voir seeder mis à jour).
            $table->enum('actor_scope', ['visitor', 'admin'])->default('visitor')->after('mode');

            // Qui doit VALIDER, uniquement si mode = 'confirm'.
            $table->enum('confirm_actor', ['visitor', 'admin'])->nullable()->after('actor_scope');
        });
    }

    public function down(): void
    {
        Schema::table('mcp_permissions', function (Blueprint $table) {
            $table->dropColumn(['actor_scope', 'confirm_actor']);
        });
    }
};
