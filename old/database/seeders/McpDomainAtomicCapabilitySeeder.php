<?php

namespace Database\Seeders;

use App\Models\Mcp\McpCapabilityActionPlaybook;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Couche ATOMIQUE de la taxonomie "Intelligence par domaine métier" —
 * chaque entrée est UNE action résolvable (1 outil, ou plusieurs
 * équivalents chez des fournisseurs concurrents). Sert de brique aux
 * workflows composites globaux (voir McpDomainIntelligenceWorkflowSeeder).
 *
 * Additif à McpCapabilityActionPlaybookSeeder (v1) — ne renomme ni ne
 * supprime les clés déjà en production, pour ne rien casser côté sites
 * ayant déjà "accepté" une suggestion existante.
 *
 * Convention de nommage : préfixe-domaine (seo-, sales-, commerce-...),
 * cohérente avec la demande. tool_names vérifiés contre
 * inventaire_toolschemas.xlsx.
 *
 * Lancer: php artisan db:seed --class=McpDomainAtomicCapabilitySeeder
 */
class McpDomainAtomicCapabilitySeeder extends Seeder
{
    public function run(): void
    {
        $playbooks = [

            // ══════════════════ SEO INTELLIGENCE ══════════════════
            [
                'key' => 'seo-search-analytics',
                'label' => 'Performance de recherche Google',
                'value_pitch' => "Clics, impressions, CTR et position moyenne — la donnée SEO la plus consultée, en un appel.",
                'applicable_type_sites' => [],
                'tool_names' => ['google_search_console__get_search_analytics'],
                'priority_tier' => 1,
            ],
            [
                'key' => 'seo-top-queries',
                'label' => 'Requêtes les plus performantes',
                'value_pitch' => "Les mots-clés qui apportent réellement du trafic, pas ceux qu'on suppose.",
                'applicable_type_sites' => [],
                'tool_names' => ['google_search_console__get_top_queries'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'seo-page-performance',
                'label' => 'Performance SEO par page',
                'value_pitch' => "Identifiez quelles pages portent votre visibilité Google, et lesquelles n'apportent rien.",
                'applicable_type_sites' => [],
                'tool_names' => ['google_search_console__get_top_pages'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'seo-keyword-analysis',
                'label' => 'Analyse d\'un mot-clé',
                'value_pitch' => "Volume de recherche, CPC et concurrence sur un mot-clé précis, avant d'y investir du contenu ou du budget.",
                'applicable_type_sites' => [],
                'tool_names' => ['semrush__get_keyword_overview'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'seo-keyword-opportunities',
                'label' => 'Opportunités de mots-clés',
                'value_pitch' => "Des mots-clés à fort potentiel que vous ne couvrez pas encore, suggérés automatiquement.",
                'applicable_type_sites' => [],
                'tool_names' => ['semrush__suggest_keyword_opportunities'],
                'priority_tier' => 1,
            ],
            [
                'key' => 'seo-domain-overview',
                'label' => 'Vue d\'ensemble d\'un domaine',
                'value_pitch' => "Mots-clés organiques, trafic estimé et budget Ads équivalent d'un domaine — le vôtre ou un concurrent.",
                'applicable_type_sites' => [],
                'tool_names' => ['semrush__get_domain_overview'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'seo-backlink-analysis',
                'label' => 'Analyse des backlinks',
                'value_pitch' => "L'autorité de votre domaine mesurée par ses liens entrants, pour juger de sa crédibilité SEO réelle.",
                'applicable_type_sites' => [],
                'tool_names' => ['semrush__get_backlinks_overview'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'seo-competitors-list',
                'label' => 'Identifier les concurrents SEO',
                'value_pitch' => "Les domaines qui partagent le plus de mots-clés avec vous — vos vrais concurrents SEO, pas les perçus.",
                'applicable_type_sites' => [],
                'tool_names' => ['semrush__get_competitors'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'seo-indexation-status',
                'label' => 'Statut des sitemaps',
                'value_pitch' => "Vérifiez que vos sitemaps sont bien lus par Google, sans attendre qu'un problème remonte de lui-même.",
                'applicable_type_sites' => [],
                'tool_names' => ['google_search_console__list_sitemaps'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'seo-url-inspection',
                'label' => 'Inspecter une URL',
                'value_pitch' => "Sachez immédiatement pourquoi une page n'apparaît pas dans Google, sans deviner.",
                'applicable_type_sites' => [],
                'tool_names' => ['google_search_console__inspect_url'],
                'priority_tier' => 1,
            ],
            [
                'key' => 'seo-request-indexing',
                'label' => 'Demander une réindexation',
                'value_pitch' => "Signalez à Google qu'une page a changé et mérite d'être réexplorée.",
                'applicable_type_sites' => [],
                'tool_names' => ['google_search_console__request_indexing'],
                'priority_tier' => 3,
            ],

            // ══════════════════ SALES INTELLIGENCE ══════════════════
            // (crm_create_contact, crm_find_contact, crm_create_opportunity,
            // crm_qualify_lead, appointment_book, appointment_check_availability
            // du seeder v1 couvrent déjà create-contact/create-opportunity/etc.
            // — entrées ci-dessous : ce qui manquait encore.)
            [
                'key' => 'sales-search-opportunities',
                'label' => 'Rechercher des opportunités',
                'value_pitch' => "Retrouvez une opportunité commerciale par critère, HubSpot ou Odoo, sans changer d'écran.",
                'applicable_type_sites' => [],
                'tool_names' => ['hubspot__search_deals', 'odoo__crm_search_leads'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'sales-log-activity',
                'label' => 'Journaliser une interaction commerciale',
                'value_pitch' => "Un appel ou un échange se documente automatiquement dans le CRM, sans ressaisie manuelle en fin de journée.",
                'applicable_type_sites' => [],
                'tool_names' => ['hubspot__log_call', 'odoo__crm_log_activity'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'sales-add-note',
                'label' => 'Ajouter une note à un contact',
                'value_pitch' => "Capturez un détail commercial important au bon endroit, immédiatement.",
                'applicable_type_sites' => [],
                'tool_names' => ['hubspot__add_note'],
                'priority_tier' => 3,
            ],

            // ══════════════════ COMMERCE INTELLIGENCE ══════════════════
            // (commerce_* du seeder v1 couvre déjà check-stock/add-to-cart/
            // checkout/order-status/search-products/create-customer.)
            [
                'key' => 'commerce-cancel-order',
                'label' => 'Annuler une commande',
                'value_pitch' => "Traitez une annulation immédiatement depuis la conversation, sans détour par le back-office.",
                'applicable_type_sites' => ['E-commerce', 'Marketplace'],
                'tool_names' => ['woocommerce__cancel_order'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'commerce-issue-refund',
                'label' => 'Émettre un remboursement',
                'value_pitch' => "Un remboursement légitime traité sans allers-retours par email.",
                'applicable_type_sites' => ['E-commerce', 'Marketplace'],
                'tool_names' => ['woocommerce__issue_refund'],
                'priority_tier' => 1,
            ],
            [
                'key' => 'commerce-track-package',
                'label' => 'Suivre un colis',
                'value_pitch' => "Répondez « où est mon colis » avec la donnée de suivi réelle, pas une estimation.",
                'applicable_type_sites' => ['E-commerce', 'Marketplace'],
                'tool_names' => ['woocommerce__track_package'],
                'priority_tier' => 1,
            ],
            [
                'key' => 'commerce-adjust-stock',
                'label' => 'Ajuster un stock',
                'value_pitch' => "Corrigez un niveau de stock erroné directement depuis la conversation admin.",
                'applicable_type_sites' => ['E-commerce', 'Marketplace'],
                'tool_names' => ['woocommerce__adjust_stock'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'commerce-recommend-products',
                'label' => 'Recommander des produits',
                'value_pitch' => "Suggérez des produits pertinents au visiteur, plutôt que de le laisser chercher seul.",
                'applicable_type_sites' => ['E-commerce', 'Marketplace'],
                'tool_names' => ['woocommerce__recommend_products'],
                'priority_tier' => 1,
            ],
            [
                'key' => 'commerce-request-return',
                'label' => 'Traiter une demande de retour',
                'value_pitch' => "Enclenchez un retour produit sans que le client n'ait à écrire au support.",
                'applicable_type_sites' => ['E-commerce', 'Marketplace'],
                'tool_names' => ['woocommerce__request_return'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'commerce-inventory-check',
                'label' => 'Vérifier un niveau de stock global',
                'value_pitch' => "Consultez le stock disponible tous entrepôts confondus (Odoo), au-delà d'un simple produit.",
                'applicable_type_sites' => ['E-commerce', 'Marketplace'],
                'tool_names' => ['odoo__inventory_check_stock'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'commerce-create-quotation',
                'label' => 'Créer un devis',
                'value_pitch' => "Générez un devis directement depuis la conversation, pour les ventes B2B pilotées sous Odoo.",
                'applicable_type_sites' => ['E-commerce', 'Marketplace', 'SaaS'],
                'tool_names' => ['odoo__sales_create_quotation'],
                'priority_tier' => 2,
            ],

            // ══════════════════ MARKETING INTELLIGENCE (compléments) ══════════════════
            [
                'key' => 'marketing-audience-list',
                'label' => 'Lister une audience email',
                'value_pitch' => "Consultez la composition d'une liste ou audience, quel que soit l'outil emailing.",
                'applicable_type_sites' => [],
                'tool_names' => ['mailchimp__list_audiences', 'klaviyo__list_lists', 'brevo__list_contact_lists'],
                'priority_tier' => 3,
            ],

            // ══════════════════ CUSTOMER SUPPORT (compléments) ══════════════════
            [
                'key' => 'support-update-ticket',
                'label' => 'Mettre à jour un ticket',
                'value_pitch' => "Faites avancer un ticket support sans changer d'écran, HubSpot ou Odoo.",
                'applicable_type_sites' => [],
                'tool_names' => ['hubspot__update_ticket', 'odoo__helpdesk_update_ticket'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'support-conversation-search',
                'label' => 'Rechercher dans les conversations',
                'value_pitch' => "Retrouvez ce qu'un visiteur a déjà demandé, sans lui faire tout répéter.",
                'applicable_type_sites' => [],
                'tool_names' => ['elchat_platform__search_conversations'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'support-unanswered-count',
                'label' => 'Compter les messages sans réponse',
                'value_pitch' => "Un indicateur simple et honnête : combien de questions sont restées sans réponse récemment.",
                'applicable_type_sites' => [],
                'tool_names' => ['elchat_platform__count_unanswered_messages'],
                'priority_tier' => 2,
            ],

            // ══════════════════ BUSINESS OPERATIONS (compléments) ══════════════════
            [
                'key' => 'operations-update-task',
                'label' => 'Mettre à jour une tâche',
                'value_pitch' => "Faites avancer une tâche projet sans changer d'outil, Asana ou Odoo.",
                'applicable_type_sites' => [],
                'tool_names' => ['asana__update_task', 'odoo__project_update_task'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'operations-search-tasks',
                'label' => 'Rechercher une tâche',
                'value_pitch' => "Retrouvez une tâche par critère plutôt que de parcourir tout le board.",
                'applicable_type_sites' => [],
                'tool_names' => ['asana__search_tasks', 'odoo__project_search_tasks'],
                'priority_tier' => 3,
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
