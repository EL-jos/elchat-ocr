<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $siteIds = DB::table('mcp_site_connectors as site_connectors')
            ->join('mcp_connectors as connectors', 'connectors.id', '=', 'site_connectors.mcp_connector_id')
            ->where('connectors.slug', 'microsoft_365')
            ->where('site_connectors.status', 'connected')
            ->pluck('site_connectors.site_id');

        $tools = [
            ['name' => 'powerpoint_list_presentations', 'mode' => 'auto'],
            ['name' => 'powerpoint_create_presentation', 'mode' => 'confirm'],
            ['name' => 'powerpoint_add_slide', 'mode' => 'confirm'],
            ['name' => 'powerpoint_export_to_pdf', 'mode' => 'confirm'],
            ['name' => 'powerpoint_delete_presentation', 'mode' => 'confirm'],
            ['name' => 'powerpoint_rename_presentation', 'mode' => 'confirm'],
            ['name' => 'powerpoint_share_presentation', 'mode' => 'confirm'],
        ];

        foreach ($siteIds as $siteId) {
            foreach ($tools as $tool) {
                $exists = DB::table('mcp_permissions')
                    ->where('site_id', $siteId)
                    ->where('connector_slug', 'microsoft_365')
                    ->where('tool_name', $tool['name'])
                    ->exists();

                if (!$exists) {
                    DB::table('mcp_permissions')->insert([
                        'site_id' => $siteId,
                        'connector_slug' => 'microsoft_365',
                        'tool_name' => $tool['name'],
                        'mode' => $tool['mode'],
                        'actor_scope' => 'admin',
                        'confirm_actor' => $tool['mode'] === 'confirm' ? 'admin' : null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('mcp_permissions')
            ->where('connector_slug', 'microsoft_365')
            ->whereIn('tool_name', [
                'powerpoint_list_presentations', 'powerpoint_create_presentation',
                'powerpoint_add_slide', 'powerpoint_export_to_pdf',
                'powerpoint_delete_presentation', 'powerpoint_rename_presentation',
                'powerpoint_share_presentation',
            ])
            ->delete();
    }
};
