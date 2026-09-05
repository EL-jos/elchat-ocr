<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('mcp_permissions')
            ->where('connector_slug', 'hubspot')
            ->where('tool_name', 'export_contacts_to_excel')
            ->delete();
    }

    public function down(): void
    {
        // The HubSpot-to-Excel integration has been intentionally retired.
    }
};
