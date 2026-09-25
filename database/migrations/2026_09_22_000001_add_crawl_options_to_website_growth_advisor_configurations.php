<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('website_growth_advisor_configurations') || Schema::hasColumn('website_growth_advisor_configurations', 'crawl_options')) {
            return;
        }

        Schema::table('website_growth_advisor_configurations', function (Blueprint $table): void {
            $table->json('crawl_options')->nullable()->after('enabled_sources');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('website_growth_advisor_configurations') && Schema::hasColumn('website_growth_advisor_configurations', 'crawl_options')) {
            Schema::table('website_growth_advisor_configurations', function (Blueprint $table): void {
                $table->dropColumn('crawl_options');
            });
        }
    }
};
