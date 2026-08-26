<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_prospecting_campaigns', function (Blueprint $table) {
            $table->dropForeign(['config_id']);
            $table->uuid('config_id')->nullable()->change();
            $table->foreign('config_id')
                ->references('id')
                ->on('sales_prospecting_configs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_prospecting_campaigns', function (Blueprint $table) {
            $table->dropForeign(['config_id']);
            $table->uuid('config_id')->nullable(false)->change();
            $table->foreign('config_id')
                ->references('id')
                ->on('sales_prospecting_configs')
                ->cascadeOnDelete();
        });
    }
};
