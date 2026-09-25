<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('website_growth_advisor_configurations')) {
            return;
        }

        $hasMode = Schema::hasColumn('website_growth_advisor_configurations', 'configuration_mode');
        $hasPreset = Schema::hasColumn('website_growth_advisor_configurations', 'preset_key');

        Schema::table('website_growth_advisor_configurations', function (Blueprint $table) use ($hasMode, $hasPreset): void {
            if (! $hasMode) {
                $table->string('configuration_mode', 16)->default('preset')->after('version');
            }
            if (! $hasPreset) {
                $table->string('preset_key', 64)->nullable()->after('configuration_mode');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('website_growth_advisor_configurations')) {
            return;
        }

        $columns = [];
        if (Schema::hasColumn('website_growth_advisor_configurations', 'preset_key')) $columns[] = 'preset_key';
        if (Schema::hasColumn('website_growth_advisor_configurations', 'configuration_mode')) $columns[] = 'configuration_mode';
        if ($columns !== []) {
            Schema::table('website_growth_advisor_configurations', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
