<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $indexes = collect(Schema::getIndexes('resource_events'))->pluck('name')->all();
        if (!in_array('resource_events_site_visitor_event_idx', $indexes, true)) {
            Schema::table('resource_events', function (Blueprint $table) {
                $table->index(['site_id', 'visitor_id', 'event_type', 'occurred_at'], 'resource_events_site_visitor_event_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::table('resource_events', function (Blueprint $table) {
            $table->dropIndex('resource_events_site_visitor_event_idx');
        });
    }
};
