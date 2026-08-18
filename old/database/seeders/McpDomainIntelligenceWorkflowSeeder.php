<?php

namespace Database\Seeders;

use App\Models\Mcp\McpWorkflow;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Couche COMPOSITE de la taxonomie "Intelligence par domaine métier" —
 * chaque entrée est une recette GLOBALE (site_id = null, donc appliquée à
 * tous les tenants sans filtrage par type/secteur, comme demandé) qui
 * enchaîne plusieurs capacités atomiques. Le README du projet le
 * confirme : un McpWorkflow n'est "pas une machine à états rigide — juste
 * un plan injecté au prompt, le LLM garde la main".
 *
 * ⚠️ DÉPENDANCE À VÉRIFIER : `steps.*.capability` référence ici les clés
 * déterministes que CapabilityActionPlaybookEngine::accept() crée
 * ("playbook_{clé}"). Si la résolution runtime de McpWorkflow exige que la
 * McpCapability existe RÉELLEMENT pour le site (via CapabilityResolver::
 * resolveToolName), ces steps ne se résoudront qu'après que l'admin ait
 * accepté la suggestion atomique correspondante dans Capacités — ce qui
 * n'est pas garanti automatiquement aujourd'hui. Proposition de suite :
 * un endpoint "provisionner ce workflow" qui accepte en masse les
 * capacités atomiques référencées, en un clic. Pas encore implémenté ici
 * en attendant confirmation du mécanisme d'exécution réel des workflows.
 *
 * Lancer: php artisan db:seed --class=McpDomainIntelligenceWorkflowSeeder
 */
class McpDomainIntelligenceWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        $workflows = [

            // ══════════════════ SEO INTELLIGENCE ══════════════════
            [
                'slug' => 'seo-overview',
                'name' => 'Vue d\'ensemble SEO',
                'trigger_description' => "L'utilisateur demande un état des lieux global de son référencement (« comment va mon SEO », « fais-moi un point SEO »).",
                'steps' => [
                    ['capability' => 'playbook_seo-search-analytics', 'label' => 'Performance de recherche Google sur les 28 derniers jours', 'optional' => false],
                    ['capability' => 'playbook_seo-domain-overview', 'label' => 'Vue d\'ensemble du domaine (mots-clés organiques, trafic estimé)', 'optional' => false],
                    ['capability' => 'playbook_seo-page-performance', 'label' => 'Pages les plus performantes', 'optional' => true],
                ],
            ],
            [
                'slug' => 'seo-site-health',
                'name' => 'Santé technique SEO',
                'trigger_description' => "L'utilisateur s'inquiète d'un problème technique, d'indexation, ou demande pourquoi une page n'apparaît pas dans Google.",
                'steps' => [
                    ['capability' => 'playbook_seo-indexation-status', 'label' => 'Statut des sitemaps soumis', 'optional' => false],
                    ['capability' => 'playbook_seo-url-inspection', 'label' => 'Inspection de l\'URL concernée si le visiteur en cite une', 'optional' => true],
                ],
            ],
            [
                'slug' => 'seo-content-gap-analysis',
                'name' => 'Angles morts de contenu SEO',
                'trigger_description' => "L'utilisateur veut savoir quels sujets ou mots-clés il ne couvre pas encore par rapport à son potentiel ou ses concurrents.",
                'steps' => [
                    ['capability' => 'playbook_seo-keyword-opportunities', 'label' => 'Mots-clés à fort potentiel non couverts', 'optional' => false],
                    ['capability' => 'playbook_seo-competitors-list', 'label' => 'Concurrents SEO identifiés', 'optional' => true],
                ],
            ],
            [
                'slug' => 'seo-competitor-analysis',
                'name' => 'Analyse concurrentielle SEO',
                'trigger_description' => "L'utilisateur veut comparer sa visibilité SEO à celle d'un concurrent nommé, ou demande qui sont ses concurrents SEO.",
                'steps' => [
                    ['capability' => 'playbook_seo-competitors-list', 'label' => 'Concurrents SEO réels (mots-clés en commun)', 'optional' => false],
                    ['capability' => 'playbook_seo-domain-overview', 'label' => 'Comparer les indicateurs du domaine propre à ceux du concurrent cité', 'optional' => false],
                ],
            ],
            [
                'slug' => 'seo-growth-report',
                'name' => 'Rapport de croissance SEO',
                'trigger_description' => "L'utilisateur demande un rapport ou une évolution de son SEO sur une période (« par rapport au mois dernier »).",
                'steps' => [
                    ['capability' => 'playbook_seo-search-analytics', 'label' => 'Performance de recherche sur la période demandée', 'optional' => false],
                    ['capability' => 'playbook_seo-search-analytics', 'label' => 'Même appel sur la période précédente équivalente, pour comparer', 'optional' => false],
                    ['capability' => 'playbook_seo-domain-overview', 'label' => 'Contexte global du domaine', 'optional' => true],
                ],
            ],
            [
                'slug' => 'seo-prioritized-action-plan',
                'name' => 'Plan d\'action SEO priorisé',
                'trigger_description' => "L'utilisateur demande concrètement quoi faire en priorité pour améliorer son SEO (« que dois-je faire en premier »).",
                'steps' => [
                    ['capability' => 'playbook_seo-indexation-status', 'label' => 'Vérifier qu\'il n\'y a pas de blocage technique en premier lieu', 'optional' => false],
                    ['capability' => 'playbook_seo-keyword-opportunities', 'label' => 'Opportunités de mots-clés à fort impact', 'optional' => false],
                    ['capability' => 'playbook_seo-page-performance', 'label' => 'Pages sous-performantes à retravailler en priorité', 'optional' => true],
                ],
            ],

            // ══════════════════ SALES INTELLIGENCE ══════════════════
            [
                'slug' => 'sales-pipeline-analysis',
                'name' => 'Analyse du pipeline commercial',
                'trigger_description' => "L'utilisateur demande un état du pipeline commercial ou des opportunités en cours.",
                'steps' => [
                    ['capability' => 'playbook_sales-search-opportunities', 'label' => 'Opportunités actuellement ouvertes', 'optional' => false],
                ],
            ],
            [
                'slug' => 'sales-follow-up',
                'name' => 'Relance commerciale',
                'trigger_description' => "L'utilisateur veut relancer un prospect ou une opportunité qui stagne.",
                'steps' => [
                    ['capability' => 'playbook_sales-search-opportunities', 'label' => 'Identifier l\'opportunité concernée', 'optional' => false],
                    ['capability' => 'playbook_sales-log-activity', 'label' => 'Journaliser la relance effectuée', 'optional' => true],
                ],
            ],
            [
                'slug' => 'sales-meeting-preparation',
                'name' => 'Préparation de rendez-vous commercial',
                'trigger_description' => "L'utilisateur se prépare pour un rendez-vous commercial à venir et veut un rappel du contexte.",
                'steps' => [
                    ['capability' => 'playbook_crm_find_contact', 'label' => 'Retrouver la fiche du contact concerné', 'optional' => false],
                    ['capability' => 'playbook_sales-search-opportunities', 'label' => 'Opportunités liées à ce contact', 'optional' => true],
                ],
            ],
            [
                'slug' => 'sales-performance-report',
                'name' => 'Rapport de performance commerciale',
                'trigger_description' => "L'utilisateur demande un rapport ou une synthèse de la performance commerciale.",
                'steps' => [
                    ['capability' => 'playbook_sales-search-opportunities', 'label' => 'Opportunités sur la période demandée', 'optional' => false],
                ],
            ],

            // ══════════════════ COMMERCE INTELLIGENCE ══════════════════
            [
                'slug' => 'commerce-order-analysis',
                'name' => 'Analyse d\'une commande',
                'trigger_description' => "L'utilisateur a une question sur une commande précise ou son acheminement.",
                'steps' => [
                    ['capability' => 'playbook_commerce_order_status', 'label' => 'Statut actuel de la commande', 'optional' => false],
                    ['capability' => 'playbook_commerce-track-package', 'label' => 'Suivi de livraison si expédiée', 'optional' => true],
                ],
            ],
            [
                'slug' => 'commerce-refund-analysis',
                'name' => 'Traitement retour & remboursement',
                'trigger_description' => "L'utilisateur demande un retour, un remboursement, ou pose une question sur une demande déjà en cours.",
                'steps' => [
                    ['capability' => 'playbook_commerce-request-return', 'label' => 'Enregistrer la demande de retour si nouvelle', 'optional' => true],
                    ['capability' => 'playbook_commerce-issue-refund', 'label' => 'Émettre le remboursement une fois le retour validé', 'optional' => true],
                ],
            ],
            [
                'slug' => 'commerce-low-stock-analysis',
                'name' => 'Analyse des ruptures de stock',
                'trigger_description' => "L'utilisateur veut savoir quels produits sont en rupture ou en stock faible.",
                'steps' => [
                    ['capability' => 'playbook_commerce-inventory-check', 'label' => 'Niveau de stock global (Odoo, si multi-entrepôt)', 'optional' => true],
                    ['capability' => 'playbook_commerce_check_stock', 'label' => 'Stock du produit précis mentionné', 'optional' => false],
                ],
            ],
            [
                'slug' => 'commerce-cross-sell-opportunities',
                'name' => 'Opportunités de vente additionnelle',
                'trigger_description' => "L'utilisateur cherche à augmenter le panier moyen ou demande des suggestions pertinentes pour un client.",
                'steps' => [
                    ['capability' => 'playbook_commerce-recommend-products', 'label' => 'Produits complémentaires pertinents', 'optional' => false],
                ],
            ],

            // ══════════════════ MARKETING INTELLIGENCE ══════════════════
            [
                'slug' => 'marketing-campaign-analysis',
                'name' => 'Analyse de campagne marketing',
                'trigger_description' => "L'utilisateur demande la performance d'une campagne marketing, email ou publicitaire.",
                'steps' => [
                    ['capability' => 'playbook_email_campaign_performance', 'label' => 'Performance de la campagne email si applicable', 'optional' => true],
                    ['capability' => 'playbook_ads_campaign_performance', 'label' => 'Performance de la campagne publicitaire si applicable', 'optional' => true],
                ],
            ],
            [
                'slug' => 'marketing-next-best-action',
                'name' => 'Prochaine meilleure action marketing',
                'trigger_description' => "L'utilisateur veut savoir quelle action marketing prioriser maintenant.",
                'steps' => [
                    ['capability' => 'playbook_ads_campaign_performance', 'label' => 'Performance publicitaire actuelle', 'optional' => true],
                    ['capability' => 'playbook_email_campaign_performance', 'label' => 'Performance email actuelle', 'optional' => true],
                    ['capability' => 'playbook_marketing-audience-list', 'label' => 'Taille et composition de l\'audience disponible', 'optional' => true],
                ],
            ],

            // ══════════════════ CUSTOMER SUPPORT ══════════════════
            [
                'slug' => 'support-ticket-analysis',
                'name' => 'Analyse des tickets support',
                'trigger_description' => "L'utilisateur demande un état des lieux sur les tickets support en cours ou récents.",
                'steps' => [
                    ['capability' => 'playbook_support_search_tickets', 'label' => 'Tickets correspondant à la demande', 'optional' => false],
                    ['capability' => 'playbook_support-unanswered-count', 'label' => 'Volume de messages sans réponse récents', 'optional' => true],
                ],
            ],

            // ══════════════════ BUSINESS OPERATIONS ══════════════════
            [
                'slug' => 'operations-weekly-summary',
                'name' => 'Résumé hebdomadaire des opérations',
                'trigger_description' => "L'utilisateur demande un résumé des tâches et projets en cours sur la semaine.",
                'steps' => [
                    ['capability' => 'playbook_tasks_list', 'label' => 'Tâches en cours', 'optional' => false],
                    ['capability' => 'playbook_operations-search-tasks', 'label' => 'Recherche ciblée si un projet est nommé', 'optional' => true],
                ],
            ],
        ];

        foreach ($workflows as $workflow) {
            McpWorkflow::updateOrCreate(
                ['site_id' => null, 'slug' => $workflow['slug']],
                ['id' => (string) Str::uuid(), 'is_active' => true, ...$workflow],
            );
        }
    }
}
