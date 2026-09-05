<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('widget_settings', function (Blueprint $table) {
            $table->boolean('ai_engagement_enabled')->default(false)->after('ai_enabled');
            $table->string('ai_engagement_widget_behavior', 32)->default('auto_open')->after('ai_engagement_enabled');
            $table->uuid('ai_engagement_agent_id')->nullable()->after('ai_engagement_widget_behavior');
            $table->uuid('ai_engagement_workflow_id')->nullable()->after('ai_engagement_agent_id');
            $table->unsignedTinyInteger('ai_engagement_max_per_session')->default(1)->after('ai_engagement_workflow_id');
            $table->unsignedTinyInteger('ai_engagement_max_per_visitor')->default(2)->after('ai_engagement_max_per_session');
            $table->unsignedInteger('ai_engagement_visitor_window_seconds')->default(86400)->after('ai_engagement_max_per_visitor');
            $table->unsignedInteger('ai_engagement_cooldown_seconds')->default(86400)->after('ai_engagement_visitor_window_seconds');
            $table->unsignedInteger('ai_engagement_close_cooldown_seconds')->default(21600)->after('ai_engagement_cooldown_seconds');
            $table->unsignedInteger('ai_engagement_refusal_cooldown_seconds')->default(604800)->after('ai_engagement_close_cooldown_seconds');
            $table->unsignedSmallInteger('ai_engagement_min_session_seconds')->default(20)->after('ai_engagement_refusal_cooldown_seconds');
            $table->unsignedTinyInteger('ai_engagement_min_pages')->default(2)->after('ai_engagement_min_session_seconds');
            $table->unsignedTinyInteger('ai_engagement_min_score')->default(60)->after('ai_engagement_min_pages');
            $table->json('ai_engagement_strategies')->nullable()->after('ai_engagement_min_score');

            $table->index('ai_engagement_enabled', 'widget_settings_ai_engagement_enabled_idx');
            $table->foreign('ai_engagement_agent_id', 'widget_settings_ai_engagement_agent_fk')
                ->references('id')->on('mcp_agents')->nullOnDelete();
            $table->foreign('ai_engagement_workflow_id', 'widget_settings_ai_engagement_workflow_fk')
                ->references('id')->on('mcp_workflows')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('widget_settings', function (Blueprint $table) {
            $table->dropForeign('widget_settings_ai_engagement_agent_fk');
            $table->dropForeign('widget_settings_ai_engagement_workflow_fk');
            $table->dropIndex('widget_settings_ai_engagement_enabled_idx');
            $table->dropColumn([
                'ai_engagement_enabled', 'ai_engagement_widget_behavior', 'ai_engagement_agent_id',
                'ai_engagement_workflow_id', 'ai_engagement_max_per_session', 'ai_engagement_max_per_visitor',
                'ai_engagement_visitor_window_seconds', 'ai_engagement_cooldown_seconds',
                'ai_engagement_close_cooldown_seconds', 'ai_engagement_refusal_cooldown_seconds',
                'ai_engagement_min_session_seconds', 'ai_engagement_min_pages', 'ai_engagement_min_score',
                'ai_engagement_strategies',
            ]);
        });
    }
};
