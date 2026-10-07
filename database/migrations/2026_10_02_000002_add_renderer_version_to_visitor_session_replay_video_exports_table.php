<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_session_replay_video_exports', function (Blueprint $table) {
            // Existing completed MP4s were rendered without rrweb text
            // normalization and at the old low-DPI profile. Treat them as v1
            // so the next request transparently queues a corrected export.
            $table->unsignedSmallInteger('renderer_version')->default(1)->after('fps');
        });
    }

    public function down(): void
    {
        Schema::table('visitor_session_replay_video_exports', function (Blueprint $table) {
            $table->dropColumn('renderer_version');
        });
    }
};
