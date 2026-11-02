<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devpilot_connections', function (Blueprint $table) {
            $table->string('site_url', 2048)->nullable()->after('protocol');
        });
    }

    public function down(): void
    {
        Schema::table('devpilot_connections', function (Blueprint $table) {
            $table->dropColumn('site_url');
        });
    }
};
