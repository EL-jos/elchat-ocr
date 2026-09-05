<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $siteIds = DB::table('mcp_site_connectors as site_connectors')
            ->join('mcp_connectors as connectors', 'connectors.id', '=', 'site_connectors.mcp_connector_id')
            ->where('connectors.slug', 'hubspot')
            ->pluck('site_connectors.site_id');

        foreach ($siteIds as $siteId) {
            $exists = DB::table('mcp_permissions')
                ->where('site_id', $siteId)
                ->where('connector_slug', 'hubspot')
                ->where('tool_name', 'export_contacts_to_excel')
                ->exists();

            if (!$exists) {
                DB::table('mcp_permissions')->insert([
                    'site_id' => $siteId,
                    'connector_slug' => 'hubspot',
                    'tool_name' => 'export_contacts_to_excel',
                    'mode' => 'confirm',
                    'actor_scope' => 'admin',
                    'confirm_actor' => 'admin',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('mcp_permissions')
            ->where('connector_slug', 'hubspot')
            ->where('tool_name', 'export_contacts_to_excel')
            ->delete();
    }
};
