<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_tiers', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('module_id', 36);
            $table->string('slug');          // 'default' (Core), 'basic', 'pro'
            $table->string('name');          // 'Basic', 'Pro'
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('module_id')->references('id')->on('modules')->onDelete('cascade');
            $table->unique(['module_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_tiers');
    }
};
