<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_capability_suggestion_dismissals', function (Blueprint $table) {
            $table->id();
            $table->uuid('site_id');
            $table->string('playbook_key');
            $table->timestamp('dismissed_at')->useCurrent();

            $table->unique(['site_id', 'playbook_key']);
            $table->foreign('site_id')->references('id')->on('sites')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_capability_suggestion_dismissals');
    }
};
