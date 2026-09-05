<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_site_connectors', function (Blueprint $table) {
            $table->string('provider_tenant_id', 128)->nullable()->after('settings');
            $table->string('provider_principal_id', 128)->nullable()->after('provider_tenant_id');
            $table->string('provider_principal_upn')->nullable()->after('provider_principal_id');
            $table->json('granted_scopes')->nullable()->after('provider_principal_upn');
            $table->index(['provider_tenant_id', 'provider_principal_id'], 'mcp_site_connector_provider_identity_idx');
        });
    }

    public function down(): void
    {
        Schema::table('mcp_site_connectors', function (Blueprint $table) {
            $table->dropIndex('mcp_site_connector_provider_identity_idx');
            $table->dropColumn(['provider_tenant_id', 'provider_principal_id', 'provider_principal_upn', 'granted_scopes']);
        });
    }
};
