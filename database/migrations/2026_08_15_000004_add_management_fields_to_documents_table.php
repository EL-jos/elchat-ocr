<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('purpose', 32)->default('knowledge')->after('type');
            $table->string('title')->nullable()->after('purpose');
            $table->string('original_name')->nullable()->after('path');
            $table->string('mime_type', 127)->nullable()->after('extension');
            $table->unsignedBigInteger('file_size')->nullable()->after('mime_type');
            // Les documents déjà présents en production sont considérés comme indexés.
            $table->string('indexing_status', 24)->default('indexed')->after('priority');
            $table->unsignedInteger('index_revision')->default(1)->after('indexing_status');
            $table->timestamp('last_indexed_at')->nullable()->after('index_revision');
            $table->text('indexing_error')->nullable()->after('last_indexed_at');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn([
                'title',
                'purpose',
                'original_name',
                'mime_type',
                'file_size',
                'indexing_status',
                'index_revision',
                'last_indexed_at',
                'indexing_error',
            ]);
        });
    }
};
