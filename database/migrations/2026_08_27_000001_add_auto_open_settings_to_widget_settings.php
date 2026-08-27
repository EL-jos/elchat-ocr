<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('widget_settings', function (Blueprint $table) {
            $table->boolean('auto_open_enabled')->default(false)->after('ai_enabled');
            $table->unsignedInteger('auto_open_delay')->default(5)->after('auto_open_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('widget_settings', function (Blueprint $table) {
            $table->dropColumn(['auto_open_enabled', 'auto_open_delay']);
        });
    }
};
