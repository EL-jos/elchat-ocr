<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_security_configurations', function (Blueprint $table): void {
            $table->json('authorized_scope')->nullable();
            $table->uuid('scope_authorized_by')->nullable()->index();
            $table->timestamp('scope_authorized_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('website_security_configurations', function (Blueprint $table): void {
            $table->dropIndex(['scope_authorized_by']);
            $table->dropColumn(['authorized_scope', 'scope_authorized_by', 'scope_authorized_at']);
        });
    }
};
