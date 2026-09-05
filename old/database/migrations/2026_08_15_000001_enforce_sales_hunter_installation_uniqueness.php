<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_agents', function (Blueprint $table) {
            $table->unique(['site_id', 'template_key'], 'mcp_agents_site_template_unique');
        });

        Schema::table('sales_prospecting_configs', function (Blueprint $table) {
            $table->unique('agent_id', 'sales_prospecting_configs_agent_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sales_prospecting_configs', function (Blueprint $table) {
            $table->dropUnique('sales_prospecting_configs_agent_unique');
        });

        Schema::table('mcp_agents', function (Blueprint $table) {
            $table->dropUnique('mcp_agents_site_template_unique');
        });
    }
};
