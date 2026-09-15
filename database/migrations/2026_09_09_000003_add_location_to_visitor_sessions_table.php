<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_sessions', function (Blueprint $table) {
            $table->string('country_code', 2)->nullable()->after('metadata');
            $table->string('country_name', 128)->nullable()->after('country_code');
            $table->string('city', 128)->nullable()->after('country_name');
            $table->string('location_status', 16)->nullable()->after('city');
            $table->timestamp('location_resolved_at')->nullable()->after('location_status');
            $table->index(['site_id', 'country_code', 'city'], 'visitor_sessions_location_idx');
        });
    }

    public function down(): void
    {
        Schema::table('visitor_sessions', function (Blueprint $table) {
            $table->dropIndex('visitor_sessions_location_idx');
            $table->dropColumn([
                'country_code',
                'country_name',
                'city',
                'location_status',
                'location_resolved_at',
            ]);
        });
    }
};
