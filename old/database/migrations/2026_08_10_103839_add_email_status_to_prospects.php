<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distinct de `status` (cycle de vie commercial) : `email_status` capture
 * spécifiquement la délivrabilité de l'adresse email, pour ne jamais
 * continuer à écrire à une adresse qui a généré un rejet définitif — sans
 * pour autant présumer une décision commerciale (do_not_contact reste
 * réservé à une demande explicite du prospect).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_prospects', function (Blueprint $table) {
            $table->enum('email_status', ['valid', 'bounced_soft', 'bounced_hard', 'complained'])
                ->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('sales_prospects', function (Blueprint $table) {
            $table->dropColumn('email_status');
        });
    }
};
