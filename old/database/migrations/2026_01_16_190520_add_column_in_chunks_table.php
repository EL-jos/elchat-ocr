<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('chunks', function (Blueprint $table) {
            $table->uuid('site_id');
            $table->string('source_type');
            $table->integer('priority');

            $table->foreign('site_id')->references('id')->on('sites')
                ->cascadeOnUpdate()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chunks', function (Blueprint $table) {
            $table->dropForeign('chunks_site_id_foreign');
            $table->dropColumn('site_id');
            $table->dropColumn('source_type');
            $table->dropColumn('priority');
        });
    }
};
