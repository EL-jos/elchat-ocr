<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_agents', function (Blueprint $table) {
            $table->boolean('can_proactively_engage')->default(false)->after('is_default');
            $table->boolean('proactive_requires_approval')->default(true)->after('can_proactively_engage');
            $table->json('proactive_channel_scope')->nullable()->after('proactive_requires_approval');
        });
    }

    public function down(): void
    {
        Schema::table('mcp_agents', function (Blueprint $table) {
            $table->dropColumn(['can_proactively_engage', 'proactive_requires_approval', 'proactive_channel_scope']);
        });
    }
};
