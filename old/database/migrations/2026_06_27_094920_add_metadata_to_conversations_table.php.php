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
        Schema::table('conversations', function (Blueprint $table) {
            // Vérifie l'absence de la colonne avant de l'ajouter
            // (idempotent si la colonne existe déjà)
            if (!Schema::hasColumn('conversations', 'metadata')) {
                $table->json('metadata')->nullable()->after('summary_updated_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            if (Schema::hasColumn('conversations', 'metadata')) {
                $table->dropColumn('metadata');
            }
        });
    }
};
