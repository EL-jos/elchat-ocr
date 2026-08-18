<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crawl_jobs', function (Blueprint $table) {
            $table->uuid('source_document_id')->nullable()->after('site_id');
            $table->index('source_document_id', 'cj_src_doc_idx');
            $table->foreign('source_document_id', 'cj_src_doc_fk')
                ->references('id')
                ->on('documents')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crawl_jobs', function (Blueprint $table) {
            $table->dropForeign('cj_src_doc_fk');
            $table->dropIndex('cj_src_doc_idx');
            $table->dropColumn('source_document_id');
        });
    }
};
