<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resource_events', function (Blueprint $table) {
            $table->uuid('conversation_id')->nullable()->change();
            $table->string('resource_type', 64)->nullable()->change();
            $table->string('event_type', 64)->change();

            $table->uuid('account_id')->nullable()->after('id');
            $table->uuid('visitor_id')->nullable()->after('site_id');
            $table->uuid('agent_id')->nullable()->after('message_id');
            $table->uuid('workflow_id')->nullable()->after('agent_id');
            $table->string('session_id', 100)->nullable()->after('workflow_id');
            $table->string('correlation_id', 100)->nullable()->after('session_id');
            $table->string('causation_id', 191)->nullable()->after('correlation_id');
            $table->uuid('parent_event_id')->nullable()->after('causation_id');
            $table->string('source', 64)->default('elchat')->after('event_type');
            $table->string('channel', 32)->nullable()->after('source');
            $table->string('idempotency_key', 191)->nullable()->after('channel');
            $table->string('attribution_type', 20)->nullable()->after('idempotency_key');
            $table->decimal('value', 18, 4)->nullable()->after('attribution_type');
            $table->char('currency', 3)->nullable()->after('value');
            $table->timestamp('occurred_at')->nullable()->after('metadata');

            $table->unique(['site_id', 'idempotency_key'], 'resource_events_site_idempotency_unique');
            $table->index(['site_id', 'event_type', 'occurred_at'], 'resource_events_event_time_idx');
            $table->index(['site_id', 'occurred_at'], 'resource_events_site_time_idx');
            $table->index(['site_id', 'agent_id', 'event_type', 'occurred_at'], 'resource_events_agent_idx');
            $table->index(['site_id', 'workflow_id', 'event_type', 'occurred_at'], 'resource_events_workflow_idx');
            $table->index(['site_id', 'correlation_id', 'event_type', 'occurred_at'], 'resource_events_correlation_idx');
        });

        DB::table('resource_events')
            ->whereNull('occurred_at')
            ->update(['occurred_at' => DB::raw('created_at')]);

        DB::table('resource_events')
            ->whereNull('correlation_id')
            ->whereNotNull('conversation_id')
            ->update(['correlation_id' => DB::raw('conversation_id')]);

        DB::table('sites')->select(['id', 'account_id'])->orderBy('id')->chunk(500, function ($sites) {
            foreach ($sites as $site) {
                DB::table('resource_events')
                    ->where('site_id', $site->id)
                    ->whereNull('account_id')
                    ->update(['account_id' => $site->account_id]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('resource_events', function (Blueprint $table) {
            $table->dropUnique('resource_events_site_idempotency_unique');
            $table->dropIndex('resource_events_event_time_idx');
            $table->dropIndex('resource_events_site_time_idx');
            $table->dropIndex('resource_events_agent_idx');
            $table->dropIndex('resource_events_workflow_idx');
            $table->dropIndex('resource_events_correlation_idx');
            $table->dropColumn([
                'account_id', 'visitor_id', 'agent_id', 'workflow_id', 'session_id',
                'correlation_id', 'causation_id', 'parent_event_id',
                'source', 'channel', 'idempotency_key', 'attribution_type',
                'value', 'currency', 'occurred_at',
            ]);
        });

        // Les trois colonnes historiques restent élargies/nullables afin qu'un
        // rollback ne détruise pas les événements business déjà collectés.
    }
};
