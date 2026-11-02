<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devpilot_connections', function (Blueprint $table): void {
            $table->boolean('can_manage_databases')->default(false)->after('can_run_commands');
        });
    }

    public function down(): void
    {
        Schema::table('devpilot_connections', function (Blueprint $table): void {
            $table->dropColumn('can_manage_databases');
        });
    }
};
