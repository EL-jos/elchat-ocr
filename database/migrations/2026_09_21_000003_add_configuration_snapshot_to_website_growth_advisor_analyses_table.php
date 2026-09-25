<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('website_growth_advisor_analyses') || Schema::hasColumn('website_growth_advisor_analyses', 'configuration_snapshot')) {
            return;
        }

        Schema::table('website_growth_advisor_analyses', function (Blueprint $table): void {
            $table->json('configuration_snapshot')->nullable()->after('include_external');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('website_growth_advisor_analyses') && Schema::hasColumn('website_growth_advisor_analyses', 'configuration_snapshot')) {
            Schema::table('website_growth_advisor_analyses', fn (Blueprint $table) => $table->dropColumn('configuration_snapshot'));
        }
    }
};
