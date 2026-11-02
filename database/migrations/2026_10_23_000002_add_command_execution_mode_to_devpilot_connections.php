<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devpilot_connections', function (Blueprint $table): void {
            $table->string('command_execution_mode', 24)->default('disabled')->after('can_run_commands');
        });

        // Preserve every existing connection's current fail-closed behavior.
        DB::table('devpilot_connections')->where('protocol', 'ssh')->where('can_run_commands', true)
            ->update(['command_execution_mode' => 'allowlist']);
    }

    public function down(): void
    {
        Schema::table('devpilot_connections', function (Blueprint $table): void {
            $table->dropColumn('command_execution_mode');
        });
    }
};
