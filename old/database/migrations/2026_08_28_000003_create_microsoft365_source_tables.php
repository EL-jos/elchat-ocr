<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('microsoft365_sync_cursors', function (Blueprint $table) {
            $table->id();
            $table->uuid('site_id');
            $table->string('provider_tenant_id', 128)->nullable();
            $table->string('provider_drive_id');
            // Empty string represents the user's OneDrive scope. A concrete
            // value represents a SharePoint site; keeping this non-null makes
            // the cursor uniqueness constraint effective on MySQL as well.
            $table->string('provider_site_id')->default('');
            $table->text('delta_link')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'provider_drive_id', 'provider_site_id'], 'microsoft365_cursor_scope_unique');
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
        });

        Schema::create('microsoft365_sources', function (Blueprint $table) {
            $table->id();
            $table->uuid('site_id');
            $table->string('provider_tenant_id', 128)->nullable();
            $table->string('provider_principal_id', 128)->nullable();
            $table->string('provider_site_id')->nullable();
            $table->string('provider_drive_id');
            $table->string('provider_item_id');
            $table->string('name');
            $table->string('mime_type', 191)->nullable();
            $table->text('web_url')->nullable();
            $table->string('etag')->nullable();
            $table->json('permissions')->nullable();
            $table->string('status', 24)->default('active');
            $table->timestamp('last_seen_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'provider_drive_id', 'provider_item_id'], 'microsoft365_source_unique');
            $table->index(['site_id', 'status']);
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('microsoft365_sources');
        Schema::dropIfExists('microsoft365_sync_cursors');
    }
};
