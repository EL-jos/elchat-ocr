<?php

namespace Database\Seeders;

use App\Models\Mcp\McpCapabilityActionPlaybook;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Platform Administration (platform-) : uniquement atomique, sur consigne
 * explicite — ce domaine ne doit jamais devenir un rapport ou une analyse
 * composite, seulement des actions de pilotage direct de la plateforme.
 *
 * Executive Intelligence (executive-) : les métriques individuelles
 * ci-dessous sont atomiques ; les rapports/diagnostics/plans d'action
 * composites sont dans McpExecutiveWorkflowSeeder.
 *
 * ⚠️ find_user/get_user (ELCHAT_PLATFORM) sont volontairement RETIRÉS
 * d'ici : ce sont des capacités CUSTOMER INTELLIGENCE (identifier un
 * client), pas de la donnée d'usage plateforme — voir
 * McpProductivityKnowledgeCustomerAtomicSeeder.
 *
 * Lancer: php artisan db:seed --class=McpPlatformExecutiveAtomicSeeder
 */
class McpPlatformExecutiveAtomicSeeder extends Seeder
{
    public function run(): void
    {
        $playbooks = [

            // ══════════════════ PLATFORM ADMINISTRATION ══════════════════
            [
                'key' => 'platform-list-agents',
                'label' => 'Lister les agents IA configurés',
                'value_pitch' => "Vue d'ensemble des agents ELChat actifs sur le compte, sans passer par l'interface d'administration.",
                'applicable_type_sites' => [],
                'tool_names' => ['elchat_platform__list_agents'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'platform-list-workflows',
                'label' => 'Lister les workflows disponibles',
                'value_pitch' => "Quels workflows sont actifs ou disponibles sur ce site, pour piloter la configuration depuis la conversation.",
                'applicable_type_sites' => [],
                'tool_names' => ['elchat_platform__list_workflows'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'platform-list-connectors',
                'label' => 'Lister les connecteurs activés',
                'value_pitch' => "Vue d'ensemble des connecteurs branchés sur ce site — utile avant de diagnostiquer un problème d'outil.",
                'applicable_type_sites' => [],
                'tool_names' => ['elchat_platform__list_connectors'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'platform-activate-workflow',
                'label' => 'Activer un workflow',
                'value_pitch' => "Activez un workflow existant directement depuis la conversation admin, sans naviguer dans les réglages.",
                'applicable_type_sites' => [],
                'tool_names' => ['elchat_platform__activate_workflow'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'platform-list-sites',
                'label' => 'Lister les sites du compte',
                'value_pitch' => "Utile pour les comptes agence gérant plusieurs sites depuis une même conversation copilote.",
                'applicable_type_sites' => [],
                'tool_names' => ['elchat_platform__list_sites'],
                'priority_tier' => 3,
            ],

            // ══════════════════ EXECUTIVE INTELLIGENCE (métriques atomiques) ══════════════════
            [
                'key' => 'executive-conversations-today',
                'label' => 'Volume de conversations du jour',
                'value_pitch' => "Le pouls de l'activité du jour, en un chiffre fiable plutôt qu'une impression.",
                'applicable_type_sites' => [],
                'tool_names' => ['elchat_platform__count_conversations_today'],
                'priority_tier' => 1,
            ],
            [
                'key' => 'executive-response-time',
                'label' => 'Temps de réponse moyen',
                'value_pitch' => "Un indicateur de qualité de service simple et immédiatement actionnable.",
                'applicable_type_sites' => [],
                'tool_names' => ['elchat_platform__average_response_time'],
                'priority_tier' => 1,
            ],
            [
                'key' => 'executive-top-questions',
                'label' => 'Questions les plus posées',
                'value_pitch' => "Ce que les visiteurs demandent vraiment, pas ce qu'on suppose qu'ils demandent.",
                'applicable_type_sites' => [],
                'tool_names' => ['elchat_platform__top_questions'],
                'priority_tier' => 1,
            ],
            [
                'key' => 'executive-channel-usage',
                'label' => 'Répartition par canal',
                'value_pitch' => "Où se concentre réellement l'activité conversationnelle (widget, WhatsApp, réseaux...).",
                'applicable_type_sites' => [],
                'tool_names' => ['elchat_platform__channels_usage'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'executive-active-visitors',
                'label' => 'Visiteurs actifs',
                'value_pitch' => "Le trafic conversationnel en temps réel, sans attendre un rapport du lendemain.",
                'applicable_type_sites' => [],
                'tool_names' => ['elchat_platform__active_visitors'],
                'priority_tier' => 1,
            ],
            [
                'key' => 'executive-new-leads',
                'label' => 'Nouveaux leads captés',
                'value_pitch' => "Le résultat commercial concret généré par le chatbot, pas seulement son activité.",
                'applicable_type_sites' => [],
                'tool_names' => ['elchat_platform__new_leads'],
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
