<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_sessions', function (Blueprint $table) {
            $table->string('acquisition_source', 64)->nullable()->after('source');
            $table->string('acquisition_medium', 64)->nullable()->after('acquisition_source');
            $table->string('acquisition_source_type', 32)->nullable()->after('acquisition_medium');
            $table->string('acquisition_campaign', 255)->nullable()->after('acquisition_source_type');
            $table->string('acquisition_term', 255)->nullable()->after('acquisition_campaign');
            $table->string('acquisition_content', 255)->nullable()->after('acquisition_term');
            $table->string('acquisition_platform', 64)->nullable()->after('acquisition_content');
            $table->text('acquisition_referrer')->nullable()->after('acquisition_platform');
            $table->string('acquisition_confidence', 16)->nullable()->after('acquisition_referrer');
            $table->timestamp('acquisition_attributed_at')->nullable()->after('acquisition_confidence');
            $table->index(
                ['site_id', 'acquisition_source_type', 'started_at'],
                'visitor_sessions_acquisition_type_idx',
            );
            $table->index(
                ['site_id', 'acquisition_source', 'started_at'],
                'visitor_sessions_acquisition_source_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('visitor_sessions', function (Blueprint $table) {
            $table->dropIndex('visitor_sessions_acquisition_type_idx');
            $table->dropIndex('visitor_sessions_acquisition_source_idx');
            $table->dropColumn([
                'acquisition_source',
                'acquisition_medium',
                'acquisition_source_type',
                'acquisition_campaign',
                'acquisition_term',
                'acquisition_content',
                'acquisition_platform',
                'acquisition_referrer',
                'acquisition_confidence',
                'acquisition_attributed_at',
            ]);
        });
    }
};
