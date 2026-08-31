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
            ['name' => 'word_get_document', 'mode' => 'auto'],
            ['name' => 'word_create_document', 'mode' => 'confirm'],
            ['name' => 'powerpoint_get_presentation', 'mode' => 'auto'],
            ['name' => 'powerpoint_upload_presentation', 'mode' => 'confirm'],
            ['name' => 'calendar_list_events', 'mode' => 'auto'],
            ['name' => 'calendar_get_event', 'mode' => 'auto'],
            ['name' => 'calendar_create_event', 'mode' => 'confirm'],
            ['name' => 'calendar_update_event', 'mode' => 'confirm'],
            ['name' => 'calendar_delete_event', 'mode' => 'confirm'],
            ['name' => 'contacts_search', 'mode' => 'auto'],
            ['name' => 'contacts_get', 'mode' => 'auto'],
            ['name' => 'contacts_create', 'mode' => 'confirm'],
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
                'word_get_document', 'word_create_document',
                'powerpoint_get_presentation', 'powerpoint_upload_presentation',
                'calendar_list_events', 'calendar_get_event', 'calendar_create_event',
                'calendar_update_event', 'calendar_delete_event', 'contacts_search',
                'contacts_get', 'contacts_create',
            ])
            ->delete();
    }
};
