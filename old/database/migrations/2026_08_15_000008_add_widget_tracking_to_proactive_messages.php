<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $indexes = collect(Schema::getIndexes('proactive_messages'))->pluck('name')->all();
        Schema::table('proactive_messages', function (Blueprint $table) {
            if (!Schema::hasColumn('proactive_messages', 'notified_at')) {
                $table->timestamp('notified_at')->nullable()->after('opened_at');
            }
            if (!Schema::hasColumn('proactive_messages', 'clicked_at')) {
                $table->timestamp('clicked_at')->nullable()->after('notified_at');
            }
        });

        Schema::table('proactive_messages', function (Blueprint $table) use ($indexes) {
            if (!in_array('pe_msg_site_channel_idx', $indexes, true)) $table->index(['site_id', 'channel', 'status'], 'pe_msg_site_channel_idx');
            if (!in_array('pe_msg_agent_st_idx', $indexes, true)) $table->index(['agent_id', 'status'], 'pe_msg_agent_st_idx');
            if (!in_array('pe_msg_workflow_st_idx', $indexes, true)) $table->index(['workflow_id', 'status'], 'pe_msg_workflow_st_idx');
        });
    }

    public function down(): void
    {
        Schema::table('proactive_messages', function (Blueprint $table) {
            $columns = array_filter([
                Schema::hasColumn('proactive_messages', 'clicked_at') ? 'clicked_at' : null,
                Schema::hasColumn('proactive_messages', 'notified_at') ? 'notified_at' : null,
            ]);
            if ($columns !== []) $table->dropColumn($columns);
        });
    }
};
