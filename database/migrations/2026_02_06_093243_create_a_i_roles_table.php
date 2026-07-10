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
        Schema::create('ai_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name'); // Commercial, Professeur, etc.
            $table->longText('prompt'); // prompt comportemental
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->uuid('ai_role_id')->nullable()->after('type_site_id');

            $table->foreign('ai_role_id')
                ->references('id')
                ->on('ai_roles')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropForeign('sites_ai_role_id_foreign');
            $table->dropColumn('ai_role_id');
        });
        Schema::dropIfExists('ai_roles');
    }
};
