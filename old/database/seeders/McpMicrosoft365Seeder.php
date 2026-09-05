<?php

namespace Database\Seeders;

use App\Models\Mcp\McpCapabilityActionPlaybook;
use App\Models\Mcp\McpWorkflow;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Référentiel ELChat des capacités Microsoft 365. Il ne crée jamais de
 * connexion ni de permission pour un site : il ne fait que déclarer les
 * actions disponibles au catalogue de capacités/workflows.
 */
class McpMicrosoft365Seeder extends Seeder
{
    public function run(): void
    {
        $playbooks = [
            ['key' => 'microsoft_document_search', 'label' => 'Rechercher un document Microsoft 365', 'value_pitch' => 'Retrouvez les fichiers OneDrive et SharePoint depuis le chat.', 'tool_names' => ['microsoft_365__documents_search'], 'priority_tier' => 1],
            ['key' => 'microsoft_document_publish', 'label' => 'Publier un document Microsoft 365', 'value_pitch' => 'Créez un fichier dans OneDrive ou SharePoint après confirmation.', 'tool_names' => ['microsoft_365__documents_upload'], 'priority_tier' => 2],
            ['key' => 'microsoft_excel_read', 'label' => 'Lire un classeur Excel', 'value_pitch' => 'Interrogez feuilles, plages et tableaux Excel sans ouvrir une application locale.', 'tool_names' => ['microsoft_365__excel_get_range'], 'priority_tier' => 1],
            ['key' => 'microsoft_excel_update', 'label' => 'Mettre à jour Excel', 'value_pitch' => 'Ajoutez une ligne ou mettez à jour une plage Excel avec confirmation.', 'tool_names' => ['microsoft_365__excel_update_range'], 'priority_tier' => 2],
            ['key' => 'microsoft_word_create', 'label' => 'Créer un document Word', 'value_pitch' => 'Générez un vrai document Word et déposez-le dans OneDrive ou SharePoint.', 'tool_names' => ['microsoft_365__word_create_document'], 'priority_tier' => 2],
            ['key' => 'microsoft_powerpoint_create', 'label' => 'Créer une présentation PowerPoint', 'value_pitch' => 'Générez un vrai fichier PowerPoint avec des titres et des puces structurées.', 'tool_names' => ['microsoft_365__powerpoint_create_presentation'], 'priority_tier' => 2],
            ['key' => 'microsoft_powerpoint_edit', 'label' => 'Modifier une présentation PowerPoint', 'value_pitch' => 'Ajoutez une diapositive structurée à une présentation PowerPoint existante.', 'tool_names' => ['microsoft_365__powerpoint_add_slide'], 'priority_tier' => 2],
            ['key' => 'microsoft_powerpoint_lifecycle', 'label' => 'Gérer le cycle de vie PowerPoint', 'value_pitch' => 'Listez, renommez, exportez, partagez ou supprimez une présentation PowerPoint après confirmation.', 'tool_names' => ['microsoft_365__powerpoint_list_presentations', 'microsoft_365__powerpoint_rename_presentation', 'microsoft_365__powerpoint_export_to_pdf', 'microsoft_365__powerpoint_share_presentation', 'microsoft_365__powerpoint_delete_presentation'], 'priority_tier' => 2],
            ['key' => 'microsoft_powerpoint_upload', 'label' => 'Déposer une présentation PowerPoint', 'value_pitch' => 'Déposez une présentation PowerPoint .pptx dans OneDrive ou SharePoint.', 'tool_names' => ['microsoft_365__powerpoint_upload_presentation'], 'priority_tier' => 2],
            ['key' => 'microsoft_calendar_manage', 'label' => 'Gérer le calendrier Outlook', 'value_pitch' => 'Consultez et gérez les événements du calendrier Outlook avec confirmation.', 'tool_names' => ['microsoft_365__calendar_list_events', 'microsoft_365__calendar_create_event'], 'priority_tier' => 1],
            ['key' => 'microsoft_contacts_manage', 'label' => 'Gérer les contacts Outlook', 'value_pitch' => 'Recherchez et créez des contacts Outlook avec confirmation.', 'tool_names' => ['microsoft_365__contacts_search', 'microsoft_365__contacts_create'], 'priority_tier' => 1],
            ['key' => 'microsoft_todo_manage', 'label' => 'Gérer Microsoft To Do', 'value_pitch' => 'Consultez et gérez les tâches To Do avec confirmation.', 'tool_names' => ['microsoft_365__todo_list_tasks', 'microsoft_365__todo_create_task'], 'priority_tier' => 1],
            ['key' => 'microsoft_lists_manage', 'label' => 'Gérer Microsoft Lists', 'value_pitch' => 'Consultez et mettez à jour les éléments de vos listes SharePoint.', 'tool_names' => ['microsoft_365__lists_list_items', 'microsoft_365__lists_create_item'], 'priority_tier' => 1],
            ['key' => 'microsoft_onenote_create', 'label' => 'Créer une page OneNote', 'value_pitch' => 'Créez une page OneNote à partir d’un contenu structuré.', 'tool_names' => ['microsoft_365__onenote_create_page'], 'priority_tier' => 2],
            ['key' => 'microsoft_outlook_draft', 'label' => 'Préparer un e-mail Outlook', 'value_pitch' => 'Préparez un brouillon Outlook sans l’envoyer automatiquement.', 'tool_names' => ['microsoft_365__outlook_create_draft'], 'priority_tier' => 1],
            ['key' => 'microsoft_outlook_send', 'label' => 'Envoyer un e-mail Outlook', 'value_pitch' => 'Envoyez un brouillon existant uniquement après validation humaine.', 'tool_names' => ['microsoft_365__outlook_send_draft'], 'priority_tier' => 2],
            ['key' => 'microsoft_team_notify', 'label' => 'Notifier un canal Teams', 'value_pitch' => 'Publiez une notification dans un canal Teams avec confirmation.', 'tool_names' => ['microsoft_365__teams_send_channel_message'], 'priority_tier' => 1],
        ];

        foreach ($playbooks as $playbook) {
            McpCapabilityActionPlaybook::updateOrCreate(
                ['key' => $playbook['key']],
                ['id' => (string) Str::uuid(), 'is_active' => true, 'applicable_type_sites' => [], ...$playbook],
            );
        }

        $workflows = [
            ['slug' => 'microsoft_prepare_and_notify', 'name' => 'Préparer un document et notifier Teams', 'trigger_description' => 'Une équipe demande de produire un compte-rendu et de prévenir un canal Teams.', 'steps' => [
                ['capability' => 'playbook_microsoft_document_publish', 'label' => 'Créer le document', 'optional' => false],
                ['capability' => 'playbook_microsoft_team_notify', 'label' => 'Notifier Teams', 'optional' => false],
            ]],
            ['slug' => 'microsoft_outlook_follow_up', 'name' => 'Préparer puis envoyer un suivi Outlook', 'trigger_description' => 'Un administrateur demande un suivi e-mail à partir d’une information connue.', 'steps' => [
                ['capability' => 'playbook_microsoft_outlook_draft', 'label' => 'Créer le brouillon', 'optional' => false],
                ['capability' => 'playbook_microsoft_outlook_send', 'label' => 'Envoyer après validation', 'optional' => false],
            ]],
        ];

        foreach ($workflows as $workflow) {
            McpWorkflow::updateOrCreate(
                ['site_id' => null, 'slug' => $workflow['slug']],
                ['id' => (string) Str::uuid(), 'is_active' => true, ...$workflow],
            );
        }
    }
}
