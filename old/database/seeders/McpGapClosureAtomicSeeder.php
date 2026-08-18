<?php

namespace Database\Seeders;

use App\Models\Mcp\McpCapabilityActionPlaybook;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Ferme 3 des 4 gaps identifiés dans l'inventaire final (search_knowledge_base,
 * agent workload/CSAT/temps de réponse HubSpot+Odoo Helpdesk, sales summary/top
 * products/abandoned checkouts WooCommerce+Shopify). Le 4e gap (Community —
 * commentaires/sentiment/horaires/réputation) reste ouvert, aucun outil ajouté dessus.
 *
 * ⚠️ Discipline : plusieurs de ces items étaient listés comme "rapports" dans
 * la demande initiale (commerce-sales-analysis, commerce-revenue-report,
 * commerce-best-selling-products, support-customer-satisfaction,
 * support-workload-analysis) mais ne nécessitent qu'UN SEUL appel d'outil.
 * Ce sont donc des capacités ATOMIQUES, pas des workflows composites — en
 * créer un workflow à une étape n'aurait ajouté aucune valeur d'orchestration
 * (cf. consigne "n'ajoute pas de capacités sans vraie valeur").
 *
 * Lancer: php artisan db:seed --class=McpGapClosureAtomicSeeder
 */
class McpGapClosureAtomicSeeder extends Seeder
{
    public function run(): void
    {
        $playbooks = [

            // ══════════════════ KNOWLEDGE MANAGEMENT ══════════════════
            [
                'key' => 'knowledge-search-site-content',
                'label' => 'Rechercher dans le contenu indexé du site',
                'value_pitch' => "Interrogez directement la base de connaissances RAG du site (pages, produits, documents indexés) — la capacité la plus attendue de ce domaine, désormais possible.",
                'applicable_type_sites' => [],
                'tool_names' => ['elchat_platform__search_knowledge_base'],
                'priority_tier' => 1,
            ],

            // ══════════════════ CUSTOMER SUPPORT (compléments) ══════════════════
            [
                'key' => 'support-agent-workload',
                'label' => 'Charge de travail des agents support',
                'value_pitch' => "Répartition réelle des tickets par agent, pour arbitrer une charge mal équilibrée avant qu'elle ne dégrade le service.",
                'applicable_type_sites' => [],
                'tool_names' => ['hubspot__get_agent_workload', 'odoo__helpdesk_get_agent_workload'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'support-csat-summary',
                'label' => 'Satisfaction client support (CSAT)',
                'value_pitch' => "Le seul indicateur qui dit si le support fonctionne du point de vue du client, pas de l'équipe.",
                'applicable_type_sites' => [],
                'tool_names' => ['hubspot__get_csat_summary', 'odoo__helpdesk_get_csat_summary'],
                'priority_tier' => 1,
            ],
            [
                'key' => 'support-response-time-stats',
                'label' => 'Statistiques de temps de première réponse',
                'value_pitch' => "Le délai réel avant qu'un client obtienne une première réponse — souvent très différent de la perception interne.",
                'applicable_type_sites' => [],
                'tool_names' => ['hubspot__get_first_response_time_stats', 'odoo__helpdesk_get_response_time_stats'],
                'priority_tier' => 1,
            ],

            // ══════════════════ COMMERCE INTELLIGENCE (compléments) ══════════════════
            [
                'key' => 'commerce-sales-summary',
                'label' => 'Synthèse des ventes',
                'value_pitch' => "Chiffre d'affaires et volume de commandes sur une période, WooCommerce ou Shopify, sans exporter de rapport manuel.",
                'applicable_type_sites' => ['E-commerce', 'Marketplace'],
                'tool_names' => ['woocommerce__get_sales_summary', 'shopify__get_sales_summary'],
                'priority_tier' => 1,
            ],
            [
                'key' => 'commerce-top-products',
                'label' => 'Produits les plus vendus',
                'value_pitch' => "Ce qui se vend réellement, pour prioriser réassort et mise en avant sans deviner.",
                'applicable_type_sites' => ['E-commerce', 'Marketplace'],
                'tool_names' => ['woocommerce__get_top_products', 'shopify__get_top_products'],
                'priority_tier' => 1,
            ],
            [
                'key' => 'commerce-abandoned-checkouts',
                'label' => 'Paniers abandonnés',
                'value_pitch' => "Le volume de ventes perdues au moment du paiement — le signal le plus actionnable pour une relance ciblée.",
                'applicable_type_sites' => ['E-commerce', 'Marketplace'],
                'tool_names' => ['shopify__get_abandoned_checkouts'], // ⚠️ pas d'équivalent WooCommerce dans l'inventaire actuel
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
