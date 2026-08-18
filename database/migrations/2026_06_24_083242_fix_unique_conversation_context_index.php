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
        // ✅ Supprimer l'ancien index
        Schema::table('social_conversations', function (Blueprint $table) {
            $table->dropForeign(['social_account_id']);
            $table->dropUnique('unique_conversation_context');
            $table->dropColumn('social_account_id');
        });

        // ✅ Ajouter une colonne context_id_hash pour l'index (SHA-256 = 64 chars fixe)
        Schema::table('social_conversations', function (Blueprint $table) {
            $table->uuid('social_account_id');

            $table->string('context_id_hash', 64)->nullable()->after('context_id');


            $table->unique(
                ['social_account_id', 'provider', 'external_user_id', 'context_type', 'context_id_hash'],
                'unique_conversation_context'
            );

            $table->foreign('social_account_id')
                ->references('id')
                ->on('social_accounts')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('social_conversations', function (Blueprint $table) {
            //
        });
    }
};
