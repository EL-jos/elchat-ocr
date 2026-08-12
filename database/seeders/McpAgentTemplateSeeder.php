<?php

namespace Database\Seeders;

use App\Models\Mcp\McpAgentTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Premier template de la Banque d'Agents. `required_module_slug` reste
 * renseigné pour le futur mais N'EST VÉRIFIÉ NULLE PART pour l'instant
 * (gratuit pendant la phase de test sur données réelles, sur consigne).
 *
 * Lancer: php artisan db:seed --class=McpAgentTemplateSeeder
 */
class McpAgentTemplateSeeder extends Seeder
{
    public function run(): void
    {
        McpAgentTemplate::updateOrCreate(
            ['key' => 'sales_hunter'],
            [
                'id' => (string) Str::uuid(),
                'name' => 'AI Sales Hunter',
                'category' => 'sales',
                'description' => "Identifie et qualifie automatiquement des prospects, prépare ou envoie des messages de prospection selon le niveau d'autonomie choisi, et vous aide à transformer les opportunités en rendez-vous.",
                'icon_url' => null,
                'required_module_slug' => 'sales_hunter', // non vérifié actuellement — voir note ci-dessus
                'default_config' => [
                    'objective' => 'generate_meetings',
                    'tone' => 'professional',
                    // skills : capacités déjà existantes (CRM/Calendar) + outils
                    // du connecteur interne sales_hunter (analyse, statut, rédaction).
                    'skills' => [
                        'crm-search-contacts', 'crm_create_contact', 'crm_find_contact', 'crm_qualify_lead',
                        'appointment_check_availability', 'appointment_book',
                        'sales_hunter__analyze_website', 'sales_hunter__save_prospect_note',
                        'sales_hunter__update_prospect_status', 'sales_hunter__draft_outreach_message',
                        // 🚫 'sales_hunter__send_prospect_message' volontairement absent :
                        // mode par défaut = 'suggestion', voir SalesProspectingController::syncAutonomyMode().
                    ],
                ],
                'bootstrap_workflow_slugs' => ['sales-pipeline-analysis', 'sales-meeting-preparation'],
                'is_active' => true,
            ],
        );
    }
}
