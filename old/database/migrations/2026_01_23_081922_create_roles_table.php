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
        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->timestamps();
        });
        Schema::table('users', function (Blueprint $table) {

            $table->dropForeign('users_account_id_foreign');
            $table->dropColumn('account_id');

            $table->uuid('role_id')->nullable()->after('id');
            $table->foreign('role_id')->references('id')->on('roles')
                ->onUpdate('cascade')->onDelete('cascade');
        });
        Schema::table('accounts', function (Blueprint $table) {
            $table->uuid('owner_user_id')->nullable()->after('id');
            $table->foreign('owner_user_id')->references('id')->on('users')
                ->onUpdate('cascade')->onDelete('cascade');
        });
        Schema::create('site_user', function (Blueprint $table) {
            $table->uuid('site_id');
            $table->uuid('user_id');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->foreign('site_id')->references('id')->on('sites')
                ->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')
                ->onUpdate('cascade')->onDelete('cascade');
            $table->primary(['site_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_user');
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropForeign('accounts_owner_user_id_foreign');
            $table->dropColumn('owner_user_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign('users_role_id_foreign');
            $table->dropColumn('role_id');

            $table->uuid('account_id')->nullable()->after('id');
            $table->foreign('account_id')->references('id')->on('accounts')
                ->onDelete('cascade');
        });
        Schema::dropIfExists('roles');
    }
};
