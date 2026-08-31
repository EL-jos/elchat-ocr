<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('origin', 32)->default('local')->after('type');
            $table->string('storage_disk', 64)->nullable()->after('path');
            $table->text('storage_path')->nullable()->after('storage_disk');
            $table->string('external_id', 255)->nullable()->after('storage_path');
            $table->string('external_drive_id', 255)->nullable()->after('external_id');
            $table->string('external_site_id', 255)->nullable()->after('external_drive_id');
            $table->string('external_etag', 255)->nullable()->after('external_site_id');
            $table->text('external_web_url')->nullable()->after('external_etag');
            $table->string('access_tenant_id', 128)->nullable()->after('external_web_url');
            $table->string('access_principal_id', 128)->nullable()->after('access_tenant_id');
            $table->json('source_metadata')->nullable()->after('access_principal_id');
            $table->index(['documentable_id', 'origin', 'external_id'], 'documents_cloud_source_idx');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex('documents_cloud_source_idx');
            $table->dropColumn([
                'origin', 'storage_disk', 'storage_path', 'external_id', 'external_drive_id',
                'external_site_id', 'external_etag', 'external_web_url', 'access_tenant_id',
                'access_principal_id', 'source_metadata',
            ]);
        });
    }
};
