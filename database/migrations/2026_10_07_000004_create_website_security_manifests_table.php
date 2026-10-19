<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_security_manifests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('site_id');
            $table->uuid('agent_id');
            $table->string('ecosystem', 24);
            $table->string('package_manager', 24);
            $table->string('lockfile_name', 191);
            $table->string('manifest_name', 191)->nullable();
            $table->string('fingerprint', 64);
            $table->unsignedInteger('component_count')->default(0);
            $table->json('components');
            $table->json('cyclonedx');
            $table->timestamps();

            $table->index(['site_id', 'ecosystem', 'created_at'], 'wsm_site_ecosystem_created_idx');
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('agent_id')->references('id')->on('mcp_agents')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_security_manifests');
    }
};
