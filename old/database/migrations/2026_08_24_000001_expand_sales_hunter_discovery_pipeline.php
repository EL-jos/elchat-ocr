<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_prospecting_configs', function (Blueprint $table) {
            $table->json('sources')->nullable()->after('icp');
            $table->json('discovery_settings')->nullable()->after('limits');
            $table->unsignedTinyInteger('minimum_score')->default(70)->after('autonomy_mode');
        });

        Schema::table('sales_prospecting_campaigns', function (Blueprint $table) {
            $table->json('sources_snapshot')->nullable()->after('schedule_snapshot');
            $table->json('configuration_snapshot')->nullable()->after('sources_snapshot');
        });

        Schema::create('sales_prospecting_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id');
            $table->string('idempotency_key');
            $table->enum('status', ['running', 'completed', 'paused', 'failed'])->default('running');
            $table->json('stats')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('campaign_id')->references('id')->on('sales_prospecting_campaigns')->onDelete('cascade');
            $table->unique(['campaign_id', 'idempotency_key']);
            $table->index(['campaign_id', 'status']);
        });

        Schema::table('sales_prospects', function (Blueprint $table) {
            $table->uuid('prospecting_run_id')->nullable()->after('campaign_id');
            $table->string('crm_sync_status')->default('pending')->after('crm_ref');
            $table->text('crm_sync_error')->nullable()->after('crm_sync_status');
            $table->json('enrichment_data')->nullable()->after('score_reasons');
            $table->json('qualification_data')->nullable()->after('enrichment_data');
            $table->string('normalized_name')->nullable()->after('name');
            $table->string('normalized_phone')->nullable()->after('phone');

            $table->foreign('prospecting_run_id')->references('id')->on('sales_prospecting_runs')->onDelete('set null');
            $table->index(['site_id', 'normalized_name', 'location']);
            $table->index(['site_id', 'crm_sync_status']);
        });

        Schema::create('sales_prospect_evidence', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('prospect_id');
            $table->string('kind'); // observation | inference | recommendation
            $table->string('source_key')->nullable();
            $table->string('source_url')->nullable();
            $table->string('field')->nullable();
            $table->json('value')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('prospect_id')->references('id')->on('sales_prospects')->onDelete('cascade');
            $table->index(['prospect_id', 'kind']);
            $table->index(['source_key', 'source_url']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_prospect_evidence');

        Schema::table('sales_prospects', function (Blueprint $table) {
            $table->dropForeign(['prospecting_run_id']);
            $table->dropColumn([
                'prospecting_run_id', 'crm_sync_status', 'crm_sync_error', 'enrichment_data',
                'qualification_data', 'normalized_name', 'normalized_phone',
            ]);
        });

        Schema::dropIfExists('sales_prospecting_runs');

        Schema::table('sales_prospecting_campaigns', function (Blueprint $table) {
            $table->dropColumn(['sources_snapshot', 'configuration_snapshot']);
        });

        Schema::table('sales_prospecting_configs', function (Blueprint $table) {
            $table->dropColumn(['sources', 'discovery_settings', 'minimum_score']);
        });
    }
};
