<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Envoyé" n'est jamais synonyme de "délivré" : l'API d'un fournisseur qui
 * accepte une requête ne garantit rien sur le sort réel du message. On
 * distingue explicitement chaque étape du cycle de vie, alimentée par les
 * événements webhook du fournisseur (delivered/bounced/complained/rejected/
 * opened/clicked), jamais supposée à l'envoi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_prospect_messages', function (Blueprint $table) {
            // provider_message_id : identifiant renvoyé par le fournisseur à
            // l'acceptation — c'est LA clé de rapprochement des événements
            // webhook ultérieurs (delivered/bounced/...), différente de notre id interne.
            $table->string('provider_message_id')->nullable()->after('external_message_id');
            $table->string('provider_key')->nullable()->after('provider_message_id'); // 'ses', 'mailgun'...
            $table->index('provider_message_id');
        });

        // MySQL : élargir un enum nécessite de le redéfinir explicitement.
        // SQLite représente déjà les enums comme du texte pendant les tests.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE sales_prospect_messages MODIFY COLUMN status ENUM(
                'draft', 'pending_approval', 'approved',
                'accepted', 'delivered', 'bounced', 'complained', 'rejected',
                'received', 'failed'
            ) NOT NULL DEFAULT 'draft'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE sales_prospect_messages MODIFY COLUMN status ENUM(
                'draft', 'pending_approval', 'approved', 'sent', 'received', 'failed'
            ) NOT NULL DEFAULT 'draft'");
        }

        Schema::table('sales_prospect_messages', function (Blueprint $table) {
            $table->dropColumn(['provider_message_id', 'provider_key']);
        });
    }
};
