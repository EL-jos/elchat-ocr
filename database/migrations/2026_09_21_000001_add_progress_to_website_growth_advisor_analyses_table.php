<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('website_growth_advisor_analyses', 'progress')) {
            return;
        }

        Schema::table('website_growth_advisor_analyses', function (Blueprint $table): void {
            $table->unsignedTinyInteger('progress')->default(0)->after('status');
            $table->string('phase', 64)->nullable()->after('progress');
            $table->string('progress_message', 500)->nullable()->after('phase');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('website_growth_advisor_analyses', 'progress')) {
            return;
        }

        Schema::table('website_growth_advisor_analyses', function (Blueprint $table): void {
            $table->dropColumn(['progress', 'phase', 'progress_message']);
        });
    }
};
