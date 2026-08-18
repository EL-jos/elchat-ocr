<?php

namespace Database\Seeders;

use App\Models\Mcp\McpCapabilityActionPlaybook;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Productivity (productivity-) : actions autour de Calendar/Asana/Slack/
 * Teams/Notion — le versant "AGIR" de Notion (create/update/append).
 *
 * Knowledge Management (knowledge-) : Drive/OneDrive/Notion — le versant
 * "RETROUVER" de Notion (search/get). Séparation volontaire, cf. consigne :
 * ne jamais mélanger action et recherche documentaire sous la même clé.
 *
 * ⚠️ Le RAG ELChat (recherche dans le contenu indexé du site) N'EST PAS
 * exposé comme ToolSchema aujourd'hui — aucun outil elchat_platform ne le
 * permet. "knowledge-search-site-content" n'est donc PAS construit ici :
 * il faudrait d'abord ajouter un tool dédié côté ElchatPlatformConnector.
 *
 * Customer Intelligence (customer-) : croise conversations ELChat, CRM,
 * e-commerce, marketing. Les briques CRM/marketing existent déjà
 * (crm_find_contact, marketing-get-subscriber) — réutilisées telles
 * quelles dans les workflows composites, jamais dupliquées ici.
 *
 * Lancer: php artisan db:seed --class=McpProductivityKnowledgeCustomerAtomicSeeder
 */
class McpProductivityKnowledgeCustomerAtomicSeeder extends Seeder
{
    public function run(): void
    {
        $playbooks = [

            // ══════════════════ PRODUCTIVITY ══════════════════
            // (appointment_book, appointment_check_availability, tasks_create,
            // tasks_list, team_notify, operations-update-task, operations-search-tasks
            // existent déjà et sont réutilisés tels quels dans les workflows.)
            [
                'key' => 'appointment_cancel',
                'label' => 'Annuler un rendez-vous',
                'value_pitch' => "Annulez un rendez-vous en un geste, quel que soit l'outil qui le porte — Calendar, HubSpot ou Odoo.",
                'applicable_type_sites' => [],
                'tool_names' => ['google_calendar__cancel_event', 'hubspot__cancel_meeting', 'odoo__appointment_cancel'],
                'priority_tier' => 1,
            ],
            [
                'key' => 'productivity-find-slots',
                'label' => 'Trouver un créneau disponible',
                'value_pitch' => "Proposez un créneau réellement libre, sans aller-retour manuel dans l'agenda.",
                'applicable_type_sites' => [],
                'tool_names' => ['google_calendar__find_available_slots'],
                'priority_tier' => 1,
            ],
            [
                'key' => 'productivity-reschedule-meeting',
                'label' => 'Déplacer un rendez-vous',
                'value_pitch' => "Reprogrammez un rendez-vous existant sans devoir l'annuler puis en recréer un.",
                'applicable_type_sites' => [],
                'tool_names' => ['google_calendar__reschedule_event'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'productivity-search-events',
                'label' => 'Rechercher un événement d\'agenda',
                'value_pitch' => "Retrouvez un rendez-vous par critère plutôt que de parcourir le calendrier.",
                'applicable_type_sites' => [],
                'tool_names' => ['google_calendar__search_events'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'productivity-add-attendee',
                'label' => 'Ajouter un participant',
                'value_pitch' => "Ajoutez quelqu'un à un rendez-vous existant sans le recréer.",
                'applicable_type_sites' => [],
                'tool_names' => ['google_calendar__add_attendee'],
                'priority_tier' => 3,
            ],
            [
                'key' => 'productivity-send-invitation',
                'label' => 'Envoyer une invitation',
                'value_pitch' => "Renvoyez l'invitation d'un rendez-vous à un participant qui l'a manquée.",
                'applicable_type_sites' => [],
                'tool_names' => ['google_calendar__send_invitation'],
                'priority_tier' => 3,
            ],
            [
                'key' => 'productivity-complete-task',
                'label' => 'Marquer une tâche comme terminée',
                'value_pitch' => "Clôturez une tâche depuis la conversation, Asana ou HubSpot.",
                'applicable_type_sites' => [],
                'tool_names' => ['asana__complete_task', 'hubspot__complete_task'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'productivity-comment-task',
                'label' => 'Commenter une tâche',
                'value_pitch' => "Ajoutez un point de contexte sur une tâche sans changer d'outil.",
                'applicable_type_sites' => [],
                'tool_names' => ['asana__add_comment'],
                'priority_tier' => 3,
            ],
            [
                'key' => 'productivity-list-channels',
                'label' => 'Lister les canaux Slack',
                'value_pitch' => "Sachez où envoyer un message avant de l'envoyer au mauvais endroit.",
                'applicable_type_sites' => [],
                'tool_names' => ['slack__list_channels'],
                'priority_tier' => 3,
            ],
            [
                'key' => 'productivity-create-channel',
                'label' => 'Créer un canal Slack',
                'value_pitch' => "Créez un canal dédié à un sujet ou un projet directement depuis la conversation.",
                'applicable_type_sites' => [],
                'tool_names' => ['slack__create_channel'],
                'priority_tier' => 3,
            ],
            [
                'key' => 'productivity-create-note',
                'label' => 'Créer une note',
                'value_pitch' => "Capturez une idée ou un point d'action immédiatement, sans changer d'application.",
                'applicable_type_sites' => [],
                'tool_names' => ['notion__create_page'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'productivity-update-note',
                'label' => 'Mettre à jour une note',
                'value_pitch' => "Corrigez ou complétez une note existante sans la rouvrir manuellement.",
                'applicable_type_sites' => [],
                'tool_names' => ['notion__update_page'],
                'priority_tier' => 3,
            ],
            [
                'key' => 'productivity-append-note',
                'label' => 'Ajouter à une note existante',
                'value_pitch' => "Complétez un compte-rendu ou un suivi au fil de l'eau, sans écraser ce qui existe.",
                'applicable_type_sites' => [],
                'tool_names' => ['notion__append_to_page'],
                'priority_tier' => 3,
            ],

            // ══════════════════ KNOWLEDGE MANAGEMENT ══════════════════
            // (document_search, document_share existent déjà et sont réutilisés.)
            [
                'key' => 'knowledge-get-file',
                'label' => 'Ouvrir un document',
                'value_pitch' => "Accédez directement au contenu d'un fichier identifié, Drive ou OneDrive.",
                'applicable_type_sites' => [],
                'tool_names' => ['google_drive__get_file', 'onedrive__get_file'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'knowledge-recent-files',
                'label' => 'Fichiers récemment modifiés',
                'value_pitch' => "Ce qui vient de bouger dans la documentation, sans devoir chercher activement.",
                'applicable_type_sites' => [],
                'tool_names' => ['google_drive__list_recent_files', 'onedrive__list_recent_files'],
                'priority_tier' => 3,
            ],
            [
                'key' => 'knowledge-upload-file',
                'label' => 'Déposer un document',
                'value_pitch' => "Ajoutez un fichier à la base documentaire directement depuis la conversation.",
                'applicable_type_sites' => [],
                'tool_names' => ['google_drive__upload_file', 'onedrive__upload_file'],
                'priority_tier' => 3,
            ],
            [
                'key' => 'knowledge-search-pages',
                'label' => 'Rechercher dans les notes/pages',
                'value_pitch' => "Retrouvez une information déjà documentée dans Notion, plutôt que de la redemander.",
                'applicable_type_sites' => [],
                'tool_names' => ['notion__search_pages'],
                'priority_tier' => 1,
            ],
            [
                'key' => 'knowledge-get-page',
                'label' => 'Ouvrir une page Notion',
                'value_pitch' => "Accédez directement au contenu complet d'une page identifiée.",
                'applicable_type_sites' => [],
                'tool_names' => ['notion__get_page'],
                'priority_tier' => 2,
            ],

            // ══════════════════ CUSTOMER INTELLIGENCE ══════════════════
            [
                'key' => 'customer-find',
                'label' => 'Retrouver un visiteur/client ELChat',
                'value_pitch' => "Identifiez un visiteur dans l'historique ELChat avant de croiser ses autres données.",
                'applicable_type_sites' => [],
                'tool_names' => ['elchat_platform__find_user'],
                'priority_tier' => 1,
            ],
            [
                'key' => 'customer-profile',
                'label' => 'Profil visiteur ELChat',
                'value_pitch' => "La fiche complète d'un visiteur telle que connue par ELChat.",
                'applicable_type_sites' => [],
                'tool_names' => ['elchat_platform__get_user'],
                'priority_tier' => 1,
            ],
            [
                'key' => 'customer-conversation-detail',
                'label' => 'Détail d\'une conversation',
                'value_pitch' => "Le contenu complet d'un échange précis, pour comprendre un contexte client.",
                'applicable_type_sites' => [],
                'tool_names' => ['elchat_platform__get_conversation'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'customer-commerce-profile',
                'label' => 'Fiche client e-commerce',
                'value_pitch' => "Retrouvez la fiche client dans la boutique, pour croiser identité web et identité d'achat.",
                'applicable_type_sites' => ['E-commerce', 'Marketplace'],
                'tool_names' => ['woocommerce__find_customer'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'customer-order-history',
                'label' => 'Historique d\'achat',
                'value_pitch' => "L'historique de commandes complet d'un client, la base de tout diagnostic de fidélité ou de risque de perte.",
                'applicable_type_sites' => ['E-commerce', 'Marketplace'],
                'tool_names' => ['woocommerce__get_customer_orders'],
                'priority_tier' => 1,
            ],
        ];

        foreach ($playbooks as $playbook) {
            McpCapabilityActionPlaybook::updateOrCreate(
                ['key' => $playbook['key']],
                ['id' => (string) Str::uuid(), 'is_active' => true, ...$playbook],
            );
        }
    }
}
