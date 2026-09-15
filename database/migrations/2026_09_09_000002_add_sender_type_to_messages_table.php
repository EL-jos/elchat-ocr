<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('sender_type', 20)->nullable()->after('role');
        });

        DB::table('messages')->whereNull('sender_type')->where('role', 'bot')->update(['sender_type' => 'ai']);
        DB::table('messages')->whereNull('sender_type')->where('role', 'user')->update(['sender_type' => 'visitor']);
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('sender_type');
        });
    }
};
