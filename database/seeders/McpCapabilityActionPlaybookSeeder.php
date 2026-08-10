<?php

namespace Database\Seeders;

use App\Models\Mcp\McpCapabilityActionPlaybook;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * ⚠️ RÈGLE DE CONCEPTION (v2 — corrige une erreur de la v1) :
 * CapabilityResolver::resolveToolName() résout une McpCapability vers UN
 * SEUL outil au runtime (le premier tool_name actif du connecteur retenu).
 * Chaque playbook ici représente donc UNE SEULE action logique, déclinée
 * en équivalents chez différents connecteurs — jamais un bundle de
 * plusieurs actions différentes (ex: jamais "pause" + "budget" dans la
 * même entrée, même connecteur ou pas : c'est ambigu à la résolution).
 *
 * tool_names vérifiés contre inventaire_toolschemas.xlsx (source de vérité
 * — remplace les noms devinés dans la v1, notamment meta_ads__resume_campaign
 * et meta_ads__update_daily_budget, mal nommés initialement).
 *
 * Lancer: php artisan db:seed --class=McpCapabilityActionPlaybookSeeder
 */
class McpCapabilityActionPlaybookSeeder extends Seeder
{
    public function run(): void
    {
        $playbooks = [

            // ══ COMMERCE (WooCommerce ⇄ Shopify — équivalence quasi 1:1) ══
            [
                'key' => 'commerce_check_stock',
                'label' => 'Vérifier le stock produit',
                'value_pitch' => "Une seule capacité pour vérifier une disponibilité, que votre boutique tourne sur WooCommerce ou Shopify.",
                'applicable_type_sites' => ['E-commerce', 'Marketplace'],
                'tool_names' => ['woocommerce__get_product_stock', 'shopify__get_product_stock'],
                'priority_tier' => 1,
            ],
            [
                'key' => 'commerce_add_to_cart',
                'label' => 'Ajouter au panier',
                'value_pitch' => "Le geste d'achat le plus fréquent, indépendant de votre plateforme e-commerce — vos workflows survivent à une migration WooCommerce ↔ Shopify.",
                'applicable_type_sites' => ['E-commerce', 'Marketplace'],
                'tool_names' => ['woocommerce__add_to_cart', 'shopify__add_to_cart'],
                'priority_tier' => 1,
            ],
            [
                'key' => 'commerce_view_cart',
                'label' => 'Consulter le panier',
                'value_pitch' => "Affichez le contenu du panier du visiteur, quelle que soit la plateforme e-commerce.",
                'applicable_type_sites' => ['E-commerce', 'Marketplace'],
                'tool_names' => ['woocommerce__get_cart', 'shopify__get_cart'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'commerce_checkout',
                'label' => 'Générer le paiement',
                'value_pitch' => "Le moment le plus critique du tunnel d'achat, disponible en une capacité unique quelle que soit votre plateforme.",
                'applicable_type_sites' => ['E-commerce', 'Marketplace'],
                'tool_names' => ['woocommerce__generate_checkout', 'shopify__generate_checkout'],
                'priority_tier' => 1,
            ],
            [
                'key' => 'commerce_order_status',
                'label' => 'Suivre une commande',
                'value_pitch' => "La question la plus fréquente des visiteurs après achat, traitée à l'identique sur WooCommerce comme Shopify.",
                'applicable_type_sites' => ['E-commerce', 'Marketplace'],
                'tool_names' => ['woocommerce__get_order_status', 'shopify__get_order_status'],
                'priority_tier' => 1,
            ],
            [
                'key' => 'commerce_search_products',
                'label' => 'Rechercher un produit',
                'value_pitch' => "Le catalogue produit interrogeable de la même façon, quelle que soit la plateforme e-commerce derrière.",
                'applicable_type_sites' => ['E-commerce', 'Marketplace'],
                'tool_names' => ['woocommerce__search_products', 'shopify__search_products'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'commerce_create_customer',
                'label' => 'Créer une fiche client',
                'value_pitch' => "Créez un compte client sans savoir ni demander quelle plateforme e-commerce le stocke réellement.",
                'applicable_type_sites' => ['E-commerce', 'Marketplace'],
                'tool_names' => ['woocommerce__create_customer', 'shopify__create_customer'],
                'priority_tier' => 3,
            ],

            // ══ SUPPORT / HELPDESK (HubSpot Tickets ⇄ Odoo Helpdesk) ══
            [
                'key' => 'support_create_ticket',
                'label' => 'Créer un ticket support',
                'value_pitch' => "Ouvrez un ticket support automatiquement pour un visiteur bloqué, que votre support tourne sur HubSpot ou Odoo.",
                'applicable_type_sites' => ['SaaS', 'Application web', 'E-commerce', 'Marketplace'],
                'tool_names' => ['hubspot__create_ticket', 'odoo__helpdesk_create_ticket'],
                'priority_tier' => 1,
            ],
            [
                'key' => 'support_get_ticket_status',
                'label' => 'Consulter le statut d\'un ticket',
                'value_pitch' => "Répondez « où en est mon ticket ? » sans que le visiteur ait besoin de préciser quel outil support vous utilisez.",
                'applicable_type_sites' => ['SaaS', 'Application web', 'E-commerce', 'Marketplace'],
                'tool_names' => ['hubspot__get_ticket', 'odoo__helpdesk_get_ticket'],
                'priority_tier' => 1,
            ],
            [
                'key' => 'support_close_ticket',
                'label' => 'Clôturer un ticket',
                'value_pitch' => "Fermez un ticket résolu directement depuis la conversation, quel que soit l'outil support en place.",
                'applicable_type_sites' => ['SaaS', 'Application web', 'E-commerce', 'Marketplace'],
                'tool_names' => ['hubspot__close_ticket', 'odoo__helpdesk_close_ticket'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'support_search_tickets',
                'label' => 'Rechercher des tickets',
                'value_pitch' => "Retrouvez l'historique support d'un client, sans changer d'écran selon l'outil derrière.",
                'applicable_type_sites' => ['SaaS', 'Application web', 'E-commerce', 'Marketplace'],
                'tool_names' => ['hubspot__search_tickets', 'odoo__helpdesk_search_tickets'],
                'priority_tier' => 2,
            ],

            // ══ CRM / SALES (HubSpot ⇄ Odoo CRM) ══
            [
                'key' => 'crm_create_contact',
                'label' => 'Créer un contact CRM',
                'value_pitch' => "Chaque nouveau contact capté par le chat atterrit dans votre CRM, HubSpot ou Odoo, sans double saisie.",
                'applicable_type_sites' => ['SaaS', 'Application web', 'Marketplace', 'E-commerce'],
                'tool_names' => ['hubspot__create_contact', 'odoo__crm_create_contact'],
                'priority_tier' => 1,
            ],
            [
                'key' => 'crm_find_contact',
                'label' => 'Retrouver un contact CRM',
                'value_pitch' => "Vérifiez si un visiteur est déjà connu de votre CRM avant de créer un doublon.",
                'applicable_type_sites' => ['SaaS', 'Application web', 'Marketplace', 'E-commerce'],
                'tool_names' => ['hubspot__find_contact', 'odoo__crm_find_contact'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'crm_create_opportunity',
                'label' => 'Créer une opportunité commerciale',
                'value_pitch' => "Un signal d'achat détecté dans la conversation devient une opportunité dans votre pipeline, HubSpot ou Odoo.",
                'applicable_type_sites' => ['SaaS', 'Application web', 'Marketplace'],
                'tool_names' => ['hubspot__create_deal', 'odoo__crm_create_lead'],
                'priority_tier' => 1,
            ],
            [
                'key' => 'crm_qualify_lead',
                'label' => 'Qualifier un lead',
                'value_pitch' => "Qualifiez automatiquement un lead selon vos critères, quel que soit le CRM qui le stocke.",
                'applicable_type_sites' => ['SaaS', 'Application web', 'Marketplace'],
                'tool_names' => ['hubspot__qualify_lead', 'odoo__crm_qualify_lead'],
                'priority_tier' => 2,
            ],

            // ══ GESTION DE TÂCHES (Asana ⇄ HubSpot ⇄ Odoo Project) ══
            [
                'key' => 'tasks_create',
                'label' => 'Créer une tâche',
                'value_pitch' => "Transformez une demande en tâche assignée dans l'outil de suivi de votre équipe — Asana, HubSpot ou Odoo — sans ressaisie.",
                'applicable_type_sites' => ['SaaS', 'Application web', 'Intranet / Extranet', 'PWA'],
                'tool_names' => ['asana__create_task', 'hubspot__create_task', 'odoo__project_create_task'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'tasks_list',
                'label' => 'Lister les tâches en cours',
                'value_pitch' => "Un état des lieux des tâches en cours, quel que soit l'outil de suivi de projet utilisé par l'équipe.",
                'applicable_type_sites' => ['SaaS', 'Application web', 'Intranet / Extranet', 'PWA'],
                'tool_names' => ['asana__list_tasks', 'hubspot__list_tasks', 'odoo__project_list_tasks'],
                'priority_tier' => 3,
            ],

            // ══ NOTIFICATION D'ÉQUIPE (Slack ⇄ Teams) ══
            [
                'key' => 'team_notify',
                'label' => 'Notifier l\'équipe',
                'value_pitch' => "Le geste le plus utilisé de tous les workflows — alerter l'équipe — fonctionne à l'identique sur Slack ou Teams, sans jamais coder en dur l'un des deux.",
                'applicable_type_sites' => [], // universel
                'tool_names' => ['slack__send_message', 'microsoft_teams__send_message'],
                'priority_tier' => 1,
            ],

            // ══ RENDEZ-VOUS / RÉSERVATION (Calendar ⇄ HubSpot ⇄ Odoo Appointment) ══
            [
                'key' => 'appointment_book',
                'label' => 'Prendre un rendez-vous',
                'value_pitch' => "Peu importe où atterrit le rendez-vous — Google Calendar, HubSpot ou le module Rendez-vous d'Odoo — une seule capacité pour vos workflows.",
                'applicable_type_sites' => ['Site vitrine', 'Landing page', 'Portail institutionnel', 'SaaS', 'Site événementiel'],
                'tool_names' => ['google_calendar__create_event', 'hubspot__create_meeting', 'odoo__appointment_book'],
                'priority_tier' => 1,
            ],
            [
                'key' => 'appointment_check_availability',
                'label' => 'Vérifier une disponibilité',
                'value_pitch' => "Avant de proposer un créneau, vérifiez la disponibilité réelle — quel que soit l'outil de calendrier derrière.",
                'applicable_type_sites' => ['Site vitrine', 'Landing page', 'Portail institutionnel', 'SaaS', 'Site événementiel'],
                'tool_names' => ['google_calendar__check_availability', 'odoo__appointment_check_availability'],
                'priority_tier' => 2,
            ],

            // ══ EMAIL MARKETING (Mailchimp ⇄ Klaviyo ⇄ Brevo) ══
            [
                'key' => 'newsletter_subscribe',
                'label' => 'Inscrire à la newsletter',
                'value_pitch' => "L'inscription newsletter la plus demandée en fin de conversation, quel que soit votre outil d'emailing.",
                'applicable_type_sites' => ['E-commerce', 'Marketplace', 'Blog', 'Site d’actualités', 'Site associatif', 'SaaS', 'Landing page'],
                'tool_names' => ['mailchimp__add_subscriber', 'klaviyo__subscribe_profile', 'brevo__add_contact_to_list'],
                'priority_tier' => 1,
            ],
            [
                'key' => 'email_campaign_performance',
                'label' => 'Consulter la performance d\'une campagne email',
                'value_pitch' => "Le taux d'ouverture ou de clic de votre dernière campagne, sans se souvenir de quel outil emailing la gère.",
                'applicable_type_sites' => ['E-commerce', 'Marketplace', 'Blog', 'SaaS', 'Site associatif'],
                'tool_names' => ['mailchimp__get_campaign_performance', 'klaviyo__get_campaign_performance', 'brevo__get_campaign_stats'],
                'priority_tier' => 3,
            ],

            // ══ RÉSEAUX SOCIAUX (Buffer ⇄ Hootsuite) ══
            [
                'key' => 'social_schedule_post',
                'label' => 'Programmer une publication',
                'value_pitch' => "Programmez une publication sociale depuis la conversation, que votre équipe pilote Buffer ou Hootsuite.",
                'applicable_type_sites' => ['Blog', 'Site d’actualités', 'E-commerce', 'Marketplace'],
                'tool_names' => ['buffer__schedule_update', 'hootsuite__schedule_message'],
                'priority_tier' => 2,
            ],

            // ══ STOCKAGE DOCUMENTAIRE (Drive ⇄ OneDrive — noms d'outils identiques) ══
            [
                'key' => 'document_search',
                'label' => 'Rechercher un document',
                'value_pitch' => "Retrouvez un fichier partagé, que votre équipe soit sur Google Drive ou OneDrive.",
                'applicable_type_sites' => ['Intranet / Extranet', 'Application web', 'SaaS', 'Documentation', 'Portail institutionnel'],
                'tool_names' => ['google_drive__search_files', 'onedrive__search_files'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'document_share',
                'label' => 'Partager un document',
                'value_pitch' => "Partagez un fichier directement depuis la conversation, indépendamment de votre solution de stockage.",
                'applicable_type_sites' => ['Intranet / Extranet', 'Application web', 'SaaS', 'Portfolio'],
                'tool_names' => ['google_drive__share_file', 'onedrive__share_file'],
                'priority_tier' => 3,
            ],

            // ══ PUBLICITÉ (Google Ads ⇄ Meta Ads — noms vérifiés, corrige la v1) ══
            [
                'key' => 'ads_pause_campaign',
                'label' => 'Mettre en pause une campagne',
                'value_pitch' => "Coupez une campagne qui dérape en un geste, quelle que soit la régie publicitaire concernée.",
                'applicable_type_sites' => ['E-commerce', 'Marketplace', 'Comparateur'],
                'tool_names' => ['google_ads__pause_campaign', 'meta_ads__pause_campaign'],
                'priority_tier' => 1,
            ],
            [
                'key' => 'ads_resume_campaign',
                'label' => 'Réactiver une campagne',
                'value_pitch' => "Relancez une campagne en pause en un geste, quelle que soit la régie publicitaire.",
                'applicable_type_sites' => ['E-commerce', 'Marketplace', 'Comparateur'],
                'tool_names' => ['google_ads__enable_campaign', 'meta_ads__resume_campaign'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'ads_update_budget',
                'label' => 'Ajuster un budget publicitaire',
                'value_pitch' => "Changez un budget quotidien sans savoir si c'est Google Ads ou Meta Ads qui le porte réellement.",
                'applicable_type_sites' => ['E-commerce', 'Marketplace', 'Comparateur'],
                'tool_names' => ['google_ads__update_campaign_budget', 'meta_ads__update_daily_budget'],
                'priority_tier' => 1,
            ],
            [
                'key' => 'ads_campaign_performance',
                'label' => 'Consulter la performance d\'une campagne',
                'value_pitch' => "La même question — « comment performe ma pub ? » — traitée à l'identique, Google Ads ou Meta Ads.",
                'applicable_type_sites' => ['E-commerce', 'Marketplace', 'Comparateur'],
                'tool_names' => ['google_ads__get_campaign_performance', 'meta_ads__get_campaign_insights'],
                'priority_tier' => 2,
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
