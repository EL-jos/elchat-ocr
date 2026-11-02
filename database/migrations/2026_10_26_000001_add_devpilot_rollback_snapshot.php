<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('devpilot_tasks', 'rollback_snapshot_encrypted')) {
            Schema::table('devpilot_tasks', function (Blueprint $table): void {
                $table->longText('rollback_snapshot_encrypted')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('devpilot_tasks', 'rollback_snapshot_encrypted')) {
            Schema::table('devpilot_tasks', function (Blueprint $table): void {
                $table->dropColumn('rollback_snapshot_encrypted');
            });
        }
    }
};
