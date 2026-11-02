<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devpilot_wordpress_site_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('site_id');
            $table->string('name', 160);
            $table->string('canonical_site_url', 2048)->nullable();
            $table->uuid('rest_connection_id')->nullable();
            $table->uuid('file_connection_id')->nullable();
            $table->uuid('ssh_connection_id')->nullable();
            $table->string('rest_root_form', 16)->nullable();
            $table->timestamps();

            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('rest_connection_id')->references('id')->on('devpilot_connections')->restrictOnDelete();
            $table->foreign('file_connection_id')->references('id')->on('devpilot_connections')->restrictOnDelete();
            $table->foreign('ssh_connection_id')->references('id')->on('devpilot_connections')->restrictOnDelete();
            $table->index(['site_id', 'name']);
        });

        Schema::table('devpilot_projects', function (Blueprint $table) {
            $table->uuid('wordpress_profile_id')->nullable();
            $table->foreign('wordpress_profile_id')
                ->references('id')->on('devpilot_wordpress_site_profiles')->restrictOnDelete();
            $table->index(['site_id', 'wordpress_profile_id']);
        });
    }

    public function down(): void
    {
        Schema::table('devpilot_projects', function (Blueprint $table) {
            $table->dropIndex(['site_id', 'wordpress_profile_id']);
            $table->dropConstrainedForeignId('wordpress_profile_id');
        });

        Schema::dropIfExists('devpilot_wordpress_site_profiles');
    }
};
