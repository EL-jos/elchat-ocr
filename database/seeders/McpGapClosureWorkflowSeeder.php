<?php

namespace Database\Seeders;

use App\Models\Mcp\McpWorkflow;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Workflows composites rendus possibles par McpGapClosureAtomicSeeder —
 * uniquement ceux qui enchaînent réellement plusieurs capacités. Les
 * rapports à un seul appel (sales-summary, top-products, csat-summary,
 * abandoned-checkouts, agent-workload...) restent des capacités atomiques
 * utilisables directement, pas des workflows à une étape.
 *
 * Met aussi à jour `knowledge-gap-check` (créé précédemment) pour qu'il
 * utilise la vraie recherche RAG désormais disponible, au lieu de
 * l'approximation par recherche Notion seule.
 *
 * Lancer: php artisan db:seed --class=McpGapClosureWorkflowSeeder
 */
class McpGapClosureWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        $workflows = [

            // ══════════════════ KNOWLEDGE MANAGEMENT ══════════════════
            [
                'slug' => 'knowledge-comprehensive-answer',
                'name' => 'Réponse documentaire complète',
                'trigger_description' => "L'utilisateur pose une question qui nécessite de croiser le contenu public du site (RAG) et la documentation interne Notion.",
                'steps' => [
                    ['capability' => 'playbook_knowledge-search-site-content', 'label' => 'Recherche dans le contenu indexé du site', 'optional' => false],
                    ['capability' => 'playbook_knowledge-search-pages', 'label' => 'Recherche complémentaire dans Notion', 'optional' => true],
                ],
            ],
            [
                // Mise à jour : remplace l'approximation initiale (recherche Notion
                // seule) par la vraie recherche RAG désormais disponible.
                'slug' => 'knowledge-gap-check',
                'name' => 'Angles morts de la documentation',
                'trigger_description' => "L'utilisateur veut savoir si sa base de connaissances couvre réellement les questions fréquentes des visiteurs.",
                'steps' => [
                    ['capability' => 'playbook_executive-top-questions', 'label' => 'Questions les plus posées par les visiteurs', 'optional' => false],
                    ['capability' => 'playbook_knowledge-search-site-content', 'label' => 'Vérifier si chaque question trouve une réponse dans le contenu indexé', 'optional' => false],
                ],
            ],

            // ══════════════════ CUSTOMER SUPPORT ══════════════════
            [
                'slug' => 'support-agent-performance',
                'name' => 'Performance des agents support',
                'trigger_description' => "L'utilisateur veut évaluer la performance globale de l'équipe ou d'un agent support.",
                'steps' => [
                    ['capability' => 'playbook_support-agent-workload', 'label' => 'Répartition de la charge par agent', 'optional' => false],
                    ['capability' => 'playbook_support-csat-summary', 'label' => 'Satisfaction client associée', 'optional' => false],
                    ['capability' => 'playbook_support-response-time-stats', 'label' => 'Délais de première réponse', 'optional' => true],
                ],
            ],
            [
                'slug' => 'support-response-quality',
                'name' => 'Qualité de réponse support',
                'trigger_description' => "L'utilisateur veut évaluer la qualité globale du support apporté (rapidité + satisfaction).",
                'steps' => [
                    ['capability' => 'playbook_support-response-time-stats', 'label' => 'Rapidité de première réponse', 'optional' => false],
                    ['capability' => 'playbook_support-csat-summary', 'label' => 'Satisfaction client', 'optional' => false],
                ],
            ],

            // ══════════════════ COMMERCE INTELLIGENCE ══════════════════
            [
                'slug' => 'commerce-sales-deep-dive',
                'name' => 'Analyse approfondie des ventes',
                'trigger_description' => "L'utilisateur veut analyser ses ventes récentes en détail (chiffre d'affaires ET produits qui le composent).",
                'steps' => [
                    ['capability' => 'playbook_commerce-sales-summary', 'label' => 'Chiffre d\'affaires et volume de commandes', 'optional' => false],
                    ['capability' => 'playbook_commerce-top-products', 'label' => 'Produits qui composent ce résultat', 'optional' => false],
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
