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
        Schema::create('widget_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('site_id');
            /*
            |--------------------------------------------------
            | Button settings
            |--------------------------------------------------
            */
            $table->string('button_text')->nullable();
            $table->string('button_background')->nullable();
            $table->string('button_color')->nullable();
            $table->enum('button_position', ['bottom-right', 'bottom-left', 'top-right', 'top-left'])->default('bottom-right');
            $table->string('button_offset_x')->default('1rem');
            $table->string('button_offset_y')->default('1rem');
            /*
            |--------------------------------------------------
            | Widget theme
            |--------------------------------------------------
            */
            $table->string('theme_primary', 20)->default('#4F46E5');
            $table->string('theme_secondary', 20)->default('#E5E7EB');
            $table->string('theme_background', 20)->default('#FFFFFF');
            $table->string('theme_color', 20)->default('#111827');
            /*
            |--------------------------------------------------
            | Chat messages
            |--------------------------------------------------
            */
            $table->string('message_user_background', 20)->default('#4F46E5');
            $table->string('message_user_color', 20)->default('#FFFFFF');

            $table->string('message_bot_background', 20)->default('#F3F4F6');
            $table->string('message_bot_color', 20)->default('#111827');
            /*
            |--------------------------------------------------
            | Bot / AI configuration
            |--------------------------------------------------
            */
            $table->boolean('widget_enabled')->default(true);

            $table->boolean('ai_enabled')->default(true);

            $table->string('bot_name')->default('ELChat');
            $table->string('bot_language', 5)->default('fr');

            $table->string('welcome_message')
                ->default('Bonjour 👋 Comment puis-je vous aider ?');

            $table->string('input_placeholder')
                ->default('Posez votre question...');


            $table->decimal('ai_temperature', 3, 2)->default(0.70);
            $table->integer('ai_max_tokens')->default(350);
            // 🔹 Seuil minimum de score (0.00 → 1.00)
            $table->decimal('min_similarity_score', 5, 2)
                ->default(0.30)
                ->comment('Minimum similarity score to consider a chunk relevant');
            // 🔹 Message par défaut si aucune réponse trouvée
            $table->string('fallback_message')
                ->default("Désolé, je n’ai pas trouvé de réponse pertinente à votre question.")
                ->comment('Message returned when no AI answer is found');

            $table->timestamps();

            $table->foreign('site_id')->references('id')->on('sites')
                ->cascadeOnUpdate()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('widget_settings');
    }
};
