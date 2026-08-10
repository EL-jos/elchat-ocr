<?php

namespace Database\Seeders;

use App\Models\Mcp\McpCapabilityActionPlaybook;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Complète Marketing Intelligence et construit Community Management, en
 * s'appuyant sur Mailchimp/Klaviyo/Brevo/Hootsuite/Buffer/Meta Ads/Google
 * Ads — tool_names vérifiés contre inventaire_toolschemas.xlsx.
 *
 * ⚠️ Zéro outil disponible aujourd'hui pour : lecture de commentaires,
 * analyse de sentiment, meilleurs horaires de publication, réputation de
 * marque — aucun connecteur de l'inventaire ne les expose. Ces items de
 * la liste Community Management ne sont PAS construits ici (fabriquer un
 * appel à un outil inexistant casserait silencieusement en production).
 * Voir McpCommunityMarketingWorkflowSeeder pour le détail de ce qui est
 * couvert vs volontairement laissé de côté.
 *
 * Additif — ne touche à aucune clé déjà en production.
 *
 * Lancer: php artisan db:seed --class=McpMarketingCommunityAtomicSeeder
 */
class McpMarketingCommunityAtomicSeeder extends Seeder
{
    public function run(): void
    {
        $playbooks = [

            // ══════════════════ MARKETING INTELLIGENCE (compléments) ══════════════════
            [
                'key' => 'marketing-list-campaigns',
                'label' => 'Lister les campagnes email',
                'value_pitch' => "Vue d'ensemble des campagnes envoyées, quel que soit l'outil emailing.",
                'applicable_type_sites' => [],
                'tool_names' => ['mailchimp__list_campaigns', 'klaviyo__list_campaigns', 'brevo__list_campaigns'],
                'priority_tier' => 3,
            ],
            [
                'key' => 'marketing-get-subscriber',
                'label' => 'Consulter un abonné email',
                'value_pitch' => "Retrouvez le profil d'un abonné (statut, historique) sans savoir quel outil emailing le stocke.",
                'applicable_type_sites' => [],
                'tool_names' => ['mailchimp__get_subscriber', 'klaviyo__get_profile', 'brevo__get_contact'],
                'priority_tier' => 3,
            ],
            [
                'key' => 'marketing-ads-account-summary',
                'label' => 'Synthèse d\'un compte publicitaire',
                'value_pitch' => "Dépense, clics et conversions globales d'un compte publicitaire, Google Ads ou Meta Ads.",
                'applicable_type_sites' => [],
                'tool_names' => ['google_ads__get_account_summary', 'meta_ads__get_account_summary'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'marketing-ads-list-campaigns',
                'label' => 'Lister les campagnes publicitaires',
                'value_pitch' => "Vue d'ensemble des campagnes actives, quelle que soit la régie publicitaire.",
                'applicable_type_sites' => [],
                'tool_names' => ['google_ads__list_campaigns', 'meta_ads__list_campaigns'],
                'priority_tier' => 3,
            ],
            [
                'key' => 'marketing-keyword-performance',
                'label' => 'Performance des mots-clés publicitaires',
                'value_pitch' => "Identifiez les mots-clés qui consomment du budget sans convertir (Google Ads).",
                'applicable_type_sites' => [],
                'tool_names' => ['google_ads__get_keyword_performance'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'marketing-adset-insights',
                'label' => 'Performance d\'un ensemble de publicités',
                'value_pitch' => "Le détail de performance d'un ad set Meta, au-delà de la vue campagne globale.",
                'applicable_type_sites' => [],
                'tool_names' => ['meta_ads__get_ad_set_insights'],
                'priority_tier' => 3,
            ],
            [
                'key' => 'marketing-traffic-overview',
                'label' => 'Vue d\'ensemble du trafic',
                'value_pitch' => "Sessions, utilisateurs et pages vues sur une période — la base de toute analyse marketing.",
                'applicable_type_sites' => [],
                'tool_names' => ['google_analytics__get_traffic_overview'],
                'priority_tier' => 1,
            ],
            [
                'key' => 'marketing-traffic-sources',
                'label' => 'Sources de trafic',
                'value_pitch' => "D'où vient réellement le trafic — organique, payant, direct, réseaux sociaux.",
                'applicable_type_sites' => [],
                'tool_names' => ['google_analytics__get_traffic_sources'],
                'priority_tier' => 1,
            ],
            [
                'key' => 'marketing-conversions',
                'label' => 'Conversions',
                'value_pitch' => "Le nombre de conversions réelles sur une période, par type d'événement.",
                'applicable_type_sites' => [],
                'tool_names' => ['google_analytics__get_conversions'],
                'priority_tier' => 1,
            ],
            [
                'key' => 'marketing-audience-demographics',
                'label' => 'Démographie de l\'audience',
                'value_pitch' => "Qui visite réellement le site — âge, localisation, appareil — pour mieux cibler.",
                'applicable_type_sites' => [],
                'tool_names' => ['google_analytics__get_audience_demographics'],
                'priority_tier' => 3,
            ],

            // ══════════════════ COMMUNITY MANAGEMENT ══════════════════
            [
                'key' => 'community-list-profiles',
                'label' => 'Lister les profils sociaux connectés',
                'value_pitch' => "Les comptes sociaux gérés, quel que soit l'outil de programmation utilisé.",
                'applicable_type_sites' => [],
                'tool_names' => ['hootsuite__list_social_profiles', 'buffer__list_profiles'],
                'priority_tier' => 3,
            ],
            [
                'key' => 'community-list-scheduled',
                'label' => 'Voir les publications programmées',
                'value_pitch' => "Le calendrier de publication à venir, Hootsuite ou Buffer, en un coup d'œil.",
                'applicable_type_sites' => [],
                'tool_names' => ['hootsuite__list_scheduled_messages', 'buffer__list_pending_updates'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'community-cancel-scheduled-post',
                'label' => 'Annuler une publication programmée',
                'value_pitch' => "Retirez une publication programmée par erreur, avant qu'elle ne parte.",
                'applicable_type_sites' => [],
                'tool_names' => ['hootsuite__delete_scheduled_message', 'buffer__delete_update'],
                'priority_tier' => 2,
            ],
            [
                'key' => 'community-post-analytics',
                'label' => 'Performance d\'une publication',
                'value_pitch' => "L'engagement réel d'une publication sociale déjà publiée.",
                'applicable_type_sites' => [],
                'tool_names' => ['buffer__get_update_analytics'], // ⚠️ pas d'équivalent Hootsuite dans l'inventaire actuel
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
