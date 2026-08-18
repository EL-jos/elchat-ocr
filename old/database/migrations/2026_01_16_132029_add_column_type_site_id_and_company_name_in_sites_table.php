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
        Schema::table('sites', function (Blueprint $table) {
            $table->uuid('type_site_id')->nullable()->after('account_id');
            $table->string('company_name')->nullable()->after('type_site_id');
            $table->json('exclude_pages')->nullable();
            $table->json('include_pages')->nullable();
            $table->foreign('type_site_id')->references('id')->on('type_sites')
                ->onUpdate('cascade')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropForeign('sites_type_site_id_foreign');
            $table->dropColumn('type_site_id');
            $table->dropColumn('company_name');
            $table->dropColumn('exclude_pages');
        });
    }
};
