<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('chat_form_submission_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('submission_id');
            $table->string('field_key');
            $table->string('file_name');
            $table->string('file_url', 500);
            $table->text('mime_type', 100);
            $table->integer('size_bytes')->nullable();
            $table->timestamps();

            $table->foreign('submission_id')->references('id')->on('chat_form_submissions')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_form_submission_files');
    }
};
