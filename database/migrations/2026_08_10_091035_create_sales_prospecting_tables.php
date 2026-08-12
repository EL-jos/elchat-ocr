<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables métier propres à AI Sales Hunter — voir architecture_ai_sales_hunter.md.
 * Tout le reste (permissions, audit d'appels d'outils, human-in-the-loop,
 * exécution CRM/Calendar) est réutilisé tel quel, rien de parallèle ici.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_prospecting_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('site_id');
            $table->uuid('agent_id'); // FK mcp_agents

            $table->json('icp'); // {sector, company_type, location, company_size, custom_criteria}
            $table->string('objective'); // generate_leads | generate_meetings | identify_prospects | promote_offer
            $table->json('limits'); // {max_prospects_per_campaign, max_new_prospects_per_day, max_outbound_actions_per_day, allowed_hours, frequency}
            $table->enum('autonomy_mode', ['suggestion', 'human_approval', 'autonomous'])->default('suggestion');
            $table->json('schedule')->nullable(); // {frequency, time, next_run_at} — géré aussi au niveau campagne

            // Connecteurs choisis explicitement par l'admin parmi ceux
            // disponibles sur le site (jamais supposés) — nullable = pas
            // encore configuré, la prospection continue sans (§13 cahier des charges).
            $table->string('crm_connector_slug')->nullable();
            $table->string('calendar_connector_slug')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('site_id')->references('id')->on('sites')->onDelete('cascade');
            $table->foreign('agent_id')->references('id')->on('mcp_agents')->onDelete('cascade');
            $table->index(['site_id', 'is_active']);
        });

        Schema::create('sales_prospecting_campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('site_id');
            $table->uuid('config_id');
            $table->string('name');
            $table->enum('status', ['draft', 'scheduled', 'running', 'completed', 'paused', 'failed'])->default('draft');
            $table->json('schedule_snapshot')->nullable(); // copie de config.schedule au moment du lancement
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('stats'); // cache rapide pour l'affichage liste, recalculable depuis prospects/reports
            $table->timestamps();

            $table->foreign('site_id')->references('id')->on('sites')->onDelete('cascade');
            $table->foreign('config_id')->references('id')->on('sales_prospecting_configs')->onDelete('cascade');
            $table->index(['site_id', 'status']);
            $table->index('next_run_at');
        });

        Schema::create('sales_prospects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('site_id');
            $table->uuid('campaign_id')->nullable(); // réutilisable au-delà d'une seule campagne

            // Conversation interne dédiée à ce prospect — voir §5 de
            // l'architecture. Permet de réutiliser TEL QUEL le
            // human-in-the-loop et MCPActionGateService::runForAgent().
            $table->uuid('conversation_id')->nullable();

            $table->string('name')->nullable();
            $table->string('company')->nullable();
            $table->string('website')->nullable();
            $table->string('domain')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('source'); // ex: 'crm_cold_contact', 'google_places' (futur)
            $table->string('location')->nullable();
            $table->string('sector')->nullable();

            $table->unsignedTinyInteger('score')->nullable();
            $table->json('score_reasons')->nullable(); // [{points, reason}]

            $table->enum('status', [
                'discovered', 'qualified', 'rejected', 'contacted', 'replied',
                'interested', 'not_interested', 'meeting_booked', 'converted', 'do_not_contact',
            ])->default('discovered');

            // {connector_slug, external_id} — jamais fabriqué à la légère,
            // renseigné uniquement après une vraie création/liaison CRM.
            $table->json('crm_ref')->nullable();

            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->foreign('site_id')->references('id')->on('sites')->onDelete('cascade');
            $table->foreign('campaign_id')->references('id')->on('sales_prospecting_campaigns')->onDelete('set null');
            $table->foreign('conversation_id')->references('id')->on('conversations')->onDelete('set null');

            // Dédup — voir §12 du cahier des charges. Nullable-safe : MySQL
            // n'applique l'unicité qu'aux valeurs non nulles sur un index unique classique.
            $table->unique(['site_id', 'domain']);
            $table->unique(['site_id', 'email']);
            $table->index(['site_id', 'status']);
        });

        Schema::create('sales_prospect_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('prospect_id');
            $table->uuid('message_id')->nullable(); // FK messages, renseigné une fois réellement envoyé/reçu
            $table->string('channel'); // 'email' (V1), extensible plus tard
            $table->enum('direction', ['outbound', 'inbound']);
            $table->enum('status', ['draft', 'pending_approval', 'approved', 'sent', 'received', 'failed'])->default('draft');
            $table->text('content');
            $table->enum('intent', [
                'interested', 'question', 'pricing', 'objection', 'not_interested', 'meeting_request', 'unsubscribe', 'unknown',
            ])->nullable(); // renseigné uniquement pour les messages entrants, après classification

            // Pour le rapprochement des réponses entrantes (References/In-Reply-To email).
            $table->string('external_message_id')->nullable();
            $table->string('in_reply_to_external_id')->nullable();

            $table->timestamps();

            $table->foreign('prospect_id')->references('id')->on('sales_prospects')->onDelete('cascade');
            $table->foreign('message_id')->references('id')->on('messages')->onDelete('set null');
            $table->index('external_message_id');
        });

        Schema::create('sales_prospecting_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id');
            $table->timestamp('generated_at');
            $table->json('stats'); // {prospects_found, prospects_qualified, prospects_rejected, messages_prepared, messages_sent, replies, interested, meetings_booked, crm_leads_created}
            $table->json('insights'); // [{text, category}] — raisons/enseignements qualitatifs, jamais un chiffre brut seul
            $table->timestamps();

            $table->foreign('campaign_id')->references('id')->on('sales_prospecting_campaigns')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_prospecting_reports');
        Schema::dropIfExists('sales_prospect_messages');
        Schema::dropIfExists('sales_prospects');
        Schema::dropIfExists('sales_prospecting_campaigns');
        Schema::dropIfExists('sales_prospecting_configs');
    }
};
