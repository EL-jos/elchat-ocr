<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TOOLS = ['write_file', 'delete_file', 'rename_file', 'create_directory', 'run_command'];

    public function up(): void
    {
        DB::table('mcp_permissions')
            ->where('connector_slug', 'devpilot')
            ->whereIn('tool_name', self::TOOLS)
            ->update(['mode' => 'deny', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Restore the removed permissions in the safest mode; their previous
        // user-configured modes cannot be reconstructed from a forward migration.
        DB::table('mcp_permissions')
            ->where('connector_slug', 'devpilot')
            ->whereIn('tool_name', self::TOOLS)
            ->where('mode', 'deny')
            ->update(['mode' => 'confirm', 'updated_at' => now()]);
    }
};
