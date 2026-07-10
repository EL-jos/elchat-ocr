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
        Schema::table('social_accounts', function (Blueprint $table) {
            // Curseur de sync (Gmail historyId, Outlook deltaLink, IMAP UID)
            $table->string('sync_cursor')->nullable()->after('token_expires_at');
            // Pour Gmail Watch et Outlook Subscription : date d'expiration
            $table->timestamp('webhook_expires_at')->nullable()->after('sync_cursor');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropColumn('sync_cursor');
            $table->dropColumn('webhook_expires_at');
        });
    }
};
