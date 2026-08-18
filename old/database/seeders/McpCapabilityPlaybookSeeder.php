<?php

namespace Database\Seeders;

use App\Models\Mcp\McpCapabilityPlaybook;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Référentiel éditorial — chaque entrée est une recommandation curée à la
 * main. `connector_slugs` est un ET logique (tous requis) : volontairement
 * jamais deux connecteurs concurrents dans une même ligne (jamais
 * woocommerce + shopify, jamais hootsuite + buffer, etc.).
 *
 * v2 : couverture étendue à l'ensemble des 18 types de site et de
 * davantage de catégories (SEO, marketing, support, CRM, commerce, sales,
 * community, ops internes) — la v1 ne couvrait que 6 types de site.
 *
 * Lancer: php artisan db:seed --class=McpCapabilityPlaybookSeeder
 */
class McpCapabilityPlaybookSeeder extends Seeder
{
    public function run(): void
    {
        $playbooks = [
            // ── v1 (inchangés) ──
            [
                'key' => 'ecommerce_acquisition_loop',
                'label' => 'Boucle acquisition → mesure → relance',
                'value_pitch' => "Pilotez vos campagnes publicitaires, votre trafic et vos relances panier au même endroit — sans changer d'outil entre la pub, la mesure et la fidélisation.",
                'applicable_type_sites' => ['E-commerce', 'Marketplace'],
                'connector_slugs' => ['google_ads', 'meta_ads', 'google_analytics', 'klaviyo'],
                'priority_tier' => 1,
            ],
            [
                'key' => 'seo_content_authority',
                'label' => 'Autorité SEO & contenu',
                'value_pitch' => "Sachez quoi écrire et ce qui fonctionne déjà : positions Google, opportunités de mots-clés et trafic réel, réunis pour piloter votre contenu sans changer d'écran.",
                'applicable_type_sites' => ['Blog', 'Site d’actualités', 'Documentation', 'Portail institutionnel', 'Site éducatif'],
                'connector_slugs' => ['google_search_console', 'semrush', 'google_analytics'],
                'priority_tier' => 1,
            ],
            [
                'key' => 'crm_pipeline_alerting',
                'label' => 'Alerte pipeline en temps réel',
                'value_pitch' => "Votre équipe est notifiée dans Slack dès qu'un signal d'achat apparaît dans votre CRM, sans surveiller le pipeline manuellement.",
                'applicable_type_sites' => ['SaaS', 'Marketplace', 'Application web'],
                'connector_slugs' => ['hubspot', 'google_analytics', 'slack'],
                'priority_tier' => 1,
            ],
            [
                'key' => 'lead_to_appointment',
                'label' => 'Capture de lead → rendez-vous qualifié',
                'value_pitch' => "Une simple question du visiteur devient un rendez-vous qualifié dans votre agenda, sans formulaire mort ni relance manuelle.",
                'applicable_type_sites' => ['Site vitrine', 'Landing page', 'Portail institutionnel'],
                'connector_slugs' => ['google_calendar', 'hubspot'],
                'priority_tier' => 1,
            ],
            [
                'key' => 'social_amplification',
                'label' => 'Amplification réseaux sociaux',
                'value_pitch' => "Programmez vos publications et mesurez leur retombée publicitaire depuis une seule vue, au lieu de jongler entre outils.",
                'applicable_type_sites' => ['E-commerce', 'Marketplace', 'Blog', 'Site d’actualités'],
                'connector_slugs' => ['hootsuite', 'meta_ads'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'internal_ops_copilot',
                'label' => 'Copilote opérations internes',
                'value_pitch' => "Centralisez pilotage interne, tâches et documentation projet sans changer d'écran — ELChat devient le point d'entrée unique de vos équipes.",
                'applicable_type_sites' => ['Intranet / Extranet', 'Application web', 'SaaS', 'PWA'],
                'connector_slugs' => ['elchat_platform', 'asana', 'notion'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'community_knowledge_ops',
                'label' => 'Boucle communauté → base de connaissances',
                'value_pitch' => "Les questions fréquentes de votre communauté remontent automatiquement vers votre base de connaissances et votre équipe.",
                'applicable_type_sites' => ['Forum / Communauté', 'Documentation', 'Site éducatif'],
                'connector_slugs' => ['notion', 'slack'],
                'priority_tier' => 3,
            ],
            [
                'key' => 'comparateur_visibility_loop',
                'label' => 'Visibilité comparateur',
                'value_pitch' => "Suivez votre visibilité face aux concurrents et l'impact réel de vos campagnes, sur un seul et même tableau de bord.",
                'applicable_type_sites' => ['Comparateur'],
                'connector_slugs' => ['semrush', 'google_ads', 'google_analytics'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'association_engagement',
                'label' => 'Engagement adhérents & événements',
                'value_pitch' => "Gardez le contact avec vos adhérents et simplifiez l'inscription à vos événements, sans double saisie.",
                'applicable_type_sites' => ['Site associatif', 'Site événementiel'],
                'connector_slugs' => ['mailchimp', 'google_calendar'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'portfolio_project_sharing',
                'label' => 'Partage de projets en équipe',
                'value_pitch' => "Vos nouveaux projets et documents sont automatiquement partagés à votre équipe, sans envoi manuel.",
                'applicable_type_sites' => ['Portfolio'],
                'connector_slugs' => ['google_drive', 'slack'],
                'priority_tier' => 3,
            ],

            // ── v2 : nouvelles entrées ──

            [
                'key' => 'woocommerce_ops_alerting',
                'label' => 'Alerte opérationnelle boutique (WooCommerce)',
                'value_pitch' => "Votre équipe est notifiée en temps réel sur les commandes à risque, litiges ou ruptures de stock — sans surveiller le back-office en continu.",
                'applicable_type_sites' => ['E-commerce'],
                'connector_slugs' => ['woocommerce', 'slack'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'shopify_ops_alerting',
                'label' => 'Alerte opérationnelle boutique (Shopify)',
                'value_pitch' => "Votre équipe est notifiée en temps réel sur les commandes à risque ou en rupture de stock — sans surveiller le back-office en continu.",
                'applicable_type_sites' => ['E-commerce'],
                'connector_slugs' => ['shopify', 'slack'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'sales_pipeline_scheduling',
                'label' => 'Pipeline commercial & prise de RDV (Odoo)',
                'value_pitch' => "Centralisez opportunités commerciales et prise de rendez-vous sans ressaisie, pour les équipes déjà sur Odoo plutôt qu'un CRM tiers.",
                'applicable_type_sites' => ['SaaS', 'Application web', 'Marketplace'],
                'connector_slugs' => ['odoo', 'google_calendar'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'helpdesk_escalation',
                'label' => 'Escalade support prioritaire',
                'value_pitch' => "Un ticket support bloquant remonte automatiquement à votre équipe sur Slack, au lieu de dormir dans une file d'attente.",
                'applicable_type_sites' => ['SaaS', 'Application web', 'E-commerce', 'Marketplace'],
                'connector_slugs' => ['odoo', 'slack'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'docs_knowledge_hub',
                'label' => 'Base de connaissances unifiée',
                'value_pitch' => "Documentation interne et fichiers partagés réunis dans une seule base interrogeable, plutôt qu'éparpillés entre deux outils.",
                'applicable_type_sites' => ['Documentation', 'SaaS', 'Application web', 'Intranet / Extranet'],
                'connector_slugs' => ['notion', 'google_drive'],
                'priority_tier' => 3,
            ],
            [
                'key' => 'newsletter_growth_loop',
                'label' => 'Croissance newsletter mesurée',
                'value_pitch' => "Mesurez l'effet réel de vos campagnes email sur le trafic et les conversions, au lieu de piloter à l'aveugle.",
                'applicable_type_sites' => ['Blog', 'Site d’actualités', 'Site associatif', 'E-commerce'],
                'connector_slugs' => ['mailchimp', 'google_analytics'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'editorial_content_planning',
                'label' => 'Planification éditoriale sociale',
                'value_pitch' => "Programmez vos publications sociales directement à partir de votre base de contenu, sans double saisie entre les deux outils.",
                'applicable_type_sites' => ['Blog', 'Site d’actualités', 'Marketplace', 'E-commerce'],
                'connector_slugs' => ['buffer', 'notion'],
                'priority_tier' => 3,
            ],
            [
                'key' => 'onedrive_teams_hub',
                'label' => 'Documents partagés dans Teams',
                'value_pitch' => "Retrouvez et partagez vos documents directement depuis Microsoft Teams, sans changer d'application.",
                'applicable_type_sites' => ['Intranet / Extranet', 'Portail institutionnel', 'Application web'],
                'connector_slugs' => ['onedrive', 'microsoft_teams'],
                'priority_tier' => 3,
            ],
            [
                'key' => 'b2b_relationship_memory',
                'label' => 'Mémoire commerciale documentée',
                'value_pitch' => "Les échanges commerciaux clés de votre CRM se documentent automatiquement dans votre base de connaissances, exploitable par toute l'équipe.",
                'applicable_type_sites' => ['SaaS', 'Application web'],
                'connector_slugs' => ['hubspot', 'notion'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'educational_engagement_loop',
                'label' => 'Suivi apprenants & visibilité',
                'value_pitch' => "Suivez ce qui attire réellement les apprenants sur votre contenu, et gardez le contact via des rappels automatisés.",
                'applicable_type_sites' => ['Site éducatif'],
                'connector_slugs' => ['google_analytics', 'mailchimp'],
                'priority_tier' => 2,
            ],
        ];

        foreach ($playbooks as $playbook) {
            McpCapabilityPlaybook::updateOrCreate(
                ['key' => $playbook['key']],
                ['id' => (string) Str::uuid(), 'is_active' => true, ...$playbook],
            );
        }
    }
}
