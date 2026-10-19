<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_security_baselines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('site_id');
            $table->uuid('audit_id');
            $table->json('snapshot');
            $table->string('snapshot_hash', 64);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['site_id', 'is_active', 'created_at'], 'wsa_baseline_site_active_idx');
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('audit_id')->references('id')->on('website_security_audits')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_security_baselines');
    }
};
