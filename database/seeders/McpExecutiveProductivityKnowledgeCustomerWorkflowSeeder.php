<?php

namespace Database\Seeders;

use App\Models\Mcp\McpWorkflow;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Aucun workflow Platform Administration ici — consigne explicite : ce
 * domaine reste 100% atomique.
 *
 * Lancer: php artisan db:seed --class=McpExecutiveProductivityKnowledgeCustomerWorkflowSeeder
 */
class McpExecutiveProductivityKnowledgeCustomerWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        $workflows = [

            // ══════════════════ EXECUTIVE INTELLIGENCE ══════════════════
            [
                'slug' => 'executive-daily-briefing',
                'name' => 'Briefing quotidien',
                'trigger_description' => "L'utilisateur demande un résumé de l'activité du jour (« quoi de neuf aujourd'hui »).",
                'steps' => [
                    ['capability' => 'playbook_executive-conversations-today', 'label' => 'Volume de conversations du jour', 'optional' => false],
                    ['capability' => 'playbook_executive-active-visitors', 'label' => 'Visiteurs actifs en ce moment', 'optional' => true],
                    ['capability' => 'playbook_executive-new-leads', 'label' => 'Nouveaux leads captés aujourd\'hui', 'optional' => false],
                ],
            ],
            [
                'slug' => 'executive-performance-diagnostic',
                'name' => 'Diagnostic de performance',
                'trigger_description' => "L'utilisateur veut diagnostiquer la qualité de service ou la performance globale de l'activité conversationnelle.",
                'steps' => [
                    ['capability' => 'playbook_executive-response-time', 'label' => 'Temps de réponse moyen', 'optional' => false],
                    ['capability' => 'playbook_executive-top-questions', 'label' => 'Questions les plus fréquentes', 'optional' => false],
                    ['capability' => 'playbook_executive-channel-usage', 'label' => 'Répartition par canal', 'optional' => true],
                ],
            ],
            [
                'slug' => 'executive-cross-domain-report',
                'name' => 'Rapport transverse',
                'trigger_description' => "L'utilisateur demande un rapport global croisant activité conversationnelle, marketing, ventes et support.",
                'steps' => [
                    ['capability' => 'playbook_executive-conversations-today', 'label' => 'Activité conversationnelle', 'optional' => false],
                    ['capability' => 'playbook_marketing-traffic-overview', 'label' => 'Trafic marketing', 'optional' => true],
                    ['capability' => 'playbook_sales-search-opportunities', 'label' => 'Pipeline commercial', 'optional' => true],
                    ['capability' => 'playbook_support_search_tickets', 'label' => 'Volume de tickets support', 'optional' => true],
                ],
            ],
            [
                'slug' => 'executive-action-plan',
                'name' => 'Plan d\'action prioritaire',
                'trigger_description' => "L'utilisateur veut un plan d'action priorisé, toutes activités confondues.",
                'steps' => [
                    ['capability' => 'playbook_executive-top-questions', 'label' => 'Frictions récurrentes côté visiteurs', 'optional' => false],
                    ['capability' => 'playbook_support-unanswered-count', 'label' => 'Volume de questions non traitées', 'optional' => true],
                    ['capability' => 'playbook_marketing-conversions', 'label' => 'Conversions récentes pour contextualiser', 'optional' => true],
                ],
            ],

            // ══════════════════ PRODUCTIVITY ══════════════════
            [
                'slug' => 'productivity-daily-agenda',
                'name' => 'Programme du jour',
                'trigger_description' => "L'utilisateur veut voir son programme du jour, rendez-vous et tâches confondus.",
                'steps' => [
                    ['capability' => 'playbook_productivity-search-events', 'label' => 'Rendez-vous du jour', 'optional' => false],
                    ['capability' => 'playbook_tasks_list', 'label' => 'Tâches en cours', 'optional' => true],
                ],
            ],
            [
                'slug' => 'productivity-meeting-setup',
                'name' => 'Organisation d\'un rendez-vous',
                'trigger_description' => "L'utilisateur veut organiser une réunion ou un rendez-vous avec quelqu'un.",
                'steps' => [
                    ['capability' => 'playbook_appointment_check_availability', 'label' => 'Vérifier la disponibilité', 'optional' => true],
                    ['capability' => 'playbook_productivity-find-slots', 'label' => 'Proposer un créneau si aucun n\'est précisé', 'optional' => true],
                    ['capability' => 'playbook_appointment_book', 'label' => 'Créer le rendez-vous', 'optional' => false],
                ],
            ],
            [
                'slug' => 'productivity-team-update',
                'name' => 'Point d\'équipe',
                'trigger_description' => "L'utilisateur veut faire un point sur les tâches en cours et notifier l'équipe.",
                'steps' => [
                    ['capability' => 'playbook_tasks_list', 'label' => 'État des tâches en cours', 'optional' => false],
                    ['capability' => 'playbook_team_notify', 'label' => 'Notifier l\'équipe du point', 'optional' => true],
                ],
            ],
            [
                'slug' => 'productivity-quick-capture',
                'name' => 'Capture rapide',
                'trigger_description' => "L'utilisateur veut noter une idée ou créer une tâche rapidement à partir de la conversation.",
                'steps' => [
                    ['capability' => 'playbook_productivity-create-note', 'label' => 'Capturer l\'idée sous forme de note', 'optional' => true],
                    ['capability' => 'playbook_tasks_create', 'label' => 'Créer une tâche assignable si une action est identifiée', 'optional' => true],
                ],
            ],

            // ══════════════════ KNOWLEDGE MANAGEMENT ══════════════════
            [
                'slug' => 'knowledge-find-document',
                'name' => 'Retrouver un document',
                'trigger_description' => "L'utilisateur cherche un document ou un fichier sans en connaître l'emplacement exact.",
                'steps' => [
                    ['capability' => 'playbook_document_search', 'label' => 'Recherche par mot-clé', 'optional' => false],
                    ['capability' => 'playbook_knowledge-recent-files', 'label' => 'Fichiers récents si la recherche ne suffit pas', 'optional' => true],
                ],
            ],
            [
                'slug' => 'knowledge-onboarding-brief',
                'name' => 'Point de mise à jour documentaire',
                'trigger_description' => "L'utilisateur cherche de la documentation pour se mettre à jour sur un sujet précis.",
                'steps' => [
                    ['capability' => 'playbook_knowledge-search-pages', 'label' => 'Pages Notion pertinentes', 'optional' => false],
                    ['capability' => 'playbook_knowledge-get-file', 'label' => 'Fichier associé si mentionné', 'optional' => true],
                ],
            ],
            [
                'slug' => 'knowledge-gap-check',
                'name' => 'Angles morts de la documentation',
                'trigger_description' => "L'utilisateur veut savoir si sa base de connaissances couvre les questions fréquentes des visiteurs.",
                'steps' => [
                    ['capability' => 'playbook_executive-top-questions', 'label' => 'Questions les plus posées par les visiteurs', 'optional' => false],
                    ['capability' => 'playbook_knowledge-search-pages', 'label' => 'Vérifier si chaque question a une page correspondante', 'optional' => false],
                ],
                // ⚠️ Sans outil de RAG exposé, la vérification de couverture reste
                // approximative — le LLM compare, il ne mesure pas un vrai taux de couverture.
            ],

            // ══════════════════ CUSTOMER INTELLIGENCE ══════════════════
            [
                'slug' => 'customer-360-view',
                'name' => 'Vue client à 360°',
                'trigger_description' => "L'utilisateur veut une vue complète d'un client précis, toutes sources confondues (conversations, CRM, achats, marketing).",
                'steps' => [
                    ['capability' => 'playbook_customer-find', 'label' => 'Identifier le visiteur dans ELChat', 'optional' => false],
                    ['capability' => 'playbook_customer-profile', 'label' => 'Profil ELChat complet', 'optional' => false],
                    ['capability' => 'playbook_crm_find_contact', 'label' => 'Fiche CRM si elle existe', 'optional' => true],
                    ['capability' => 'playbook_customer-commerce-profile', 'label' => 'Fiche client e-commerce si applicable', 'optional' => true],
                    ['capability' => 'playbook_marketing-get-subscriber', 'label' => 'Statut abonné email si applicable', 'optional' => true],
                ],
            ],
            [
                'slug' => 'customer-purchase-and-support-history',
                'name' => 'Historique achats & support',
                'trigger_description' => "L'utilisateur veut l'historique d'achat ET de support d'un client donné.",
                'steps' => [
                    ['capability' => 'playbook_customer-order-history', 'label' => 'Historique de commandes', 'optional' => false],
                    ['capability' => 'playbook_support_search_tickets', 'label' => 'Tickets support liés', 'optional' => true],
                ],
            ],
            [
                'slug' => 'customer-engagement-assessment',
                'name' => 'Évaluation de l\'engagement client',
                'trigger_description' => "L'utilisateur veut évaluer l'engagement global d'un client (actif, silencieux, à risque).",
                'steps' => [
                    ['capability' => 'playbook_customer-conversation-detail', 'label' => 'Dernières interactions conversationnelles', 'optional' => false],
                    ['capability' => 'playbook_customer-order-history', 'label' => 'Fréquence et récence d\'achat', 'optional' => true],
                    ['capability' => 'playbook_marketing-get-subscriber', 'label' => 'Statut d\'engagement email', 'optional' => true],
                ],
                // ⚠️ "Engagement"/risque = synthèse du LLM sur des signaux bruts,
                // aucun outil de scoring dédié n'existe.
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
