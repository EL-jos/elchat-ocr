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
        Schema::create('social_account_user', function (Blueprint $table) {
            // ─── clés étrangères ──────────────────────────────────────────
            $table->uuid('social_account_id', 36);
            $table->uuid('user_id', 36);

            // ─── contexte de liaison ──────────────────────────────────────
            // provider + external_user_id = identité du User SUR ce canal
            // ex: provider='youtube', external_user_id='UC...'
            $table->string('provider', 50);
            $table->string('external_user_id', 255);

            // ─── métadonnées optionnelles ─────────────────────────────────
            $table->string('external_username', 255)->nullable();
            $table->string('external_display_name', 255)->nullable();

            $table->timestamps();

            // ─── PK composite ─────────────────────────────────────────────
            $table->primary(['social_account_id', 'user_id', 'provider', 'external_user_id'],
                'social_account_user_primary');

            // ─── FK ───────────────────────────────────────────────────────
            $table->foreign('social_account_id')
                ->references('id')->on('social_accounts')
                ->cascadeOnDelete();

            $table->foreign('user_id')
                ->references('id')->on('users')
                ->cascadeOnDelete();

            // ─── index utiles ─────────────────────────────────────────────
            // Recherche rapide "ce external_user_id est-il déjà un User ?"
            $table->index(['provider', 'external_user_id'], 'idx_provider_external_user');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
        });
        Schema::dropIfExists('social_account_user');
    }
};
