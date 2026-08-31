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
            ['name' => 'todo_list_lists', 'mode' => 'auto'],
            ['name' => 'todo_create_list', 'mode' => 'confirm'],
            ['name' => 'todo_list_tasks', 'mode' => 'auto'],
            ['name' => 'todo_get_task', 'mode' => 'auto'],
            ['name' => 'todo_create_task', 'mode' => 'confirm'],
            ['name' => 'todo_update_task', 'mode' => 'confirm'],
            ['name' => 'todo_delete_task', 'mode' => 'confirm'],
            ['name' => 'lists_list_lists', 'mode' => 'auto'],
            ['name' => 'lists_get_list', 'mode' => 'auto'],
            ['name' => 'lists_list_items', 'mode' => 'auto'],
            ['name' => 'lists_get_item', 'mode' => 'auto'],
            ['name' => 'lists_create_item', 'mode' => 'confirm'],
            ['name' => 'lists_update_item', 'mode' => 'confirm'],
            ['name' => 'lists_delete_item', 'mode' => 'confirm'],
            ['name' => 'onenote_list_notebooks', 'mode' => 'auto'],
            ['name' => 'onenote_list_sections', 'mode' => 'auto'],
            ['name' => 'onenote_list_pages', 'mode' => 'auto'],
            ['name' => 'onenote_get_page', 'mode' => 'auto'],
            ['name' => 'onenote_get_page_content', 'mode' => 'auto'],
            ['name' => 'onenote_create_page', 'mode' => 'confirm'],
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
                'todo_list_lists', 'todo_create_list', 'todo_list_tasks', 'todo_get_task',
                'todo_create_task', 'todo_update_task', 'todo_delete_task', 'lists_list_lists',
                'lists_get_list', 'lists_list_items', 'lists_get_item', 'lists_create_item',
                'lists_update_item', 'lists_delete_item', 'onenote_list_notebooks',
                'onenote_list_sections', 'onenote_list_pages', 'onenote_get_page',
                'onenote_get_page_content', 'onenote_create_page',
            ])
            ->delete();
    }
};
