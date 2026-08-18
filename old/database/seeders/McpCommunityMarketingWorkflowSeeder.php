<?php

namespace Database\Seeders;

use App\Models\Mcp\McpWorkflow;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Couche composite pour Marketing Intelligence (14/14 items couverts) et
 * Community Management (7/11 items — voir NON COUVERTS ci-dessous).
 *
 * NON COUVERTS, faute d'outil disponible dans l'inventaire actuel :
 * - community-comment-analysis   : aucun outil de lecture de commentaires
 * - community-sentiment-analysis : aucune analyse de sentiment exposée
 * - community-best-posting-times : aucune donnée temporelle d'engagement
 * - community-brand-reputation   : aucun outil de veille/mentions
 * → à ajouter au jour où HootsuiteConnector/BufferConnector (ou un futur
 * connecteur de veille) exposeront ces données. Les inventer aujourd'hui
 * produirait des workflows qui échouent silencieusement en production.
 *
 * community-content-ideas réutilise volontairement une capacité SEO
 * (playbook_seo-keyword-opportunities) : une opportunité de mot-clé EST
 * une idée de contenu — réutilisation intentionnelle, pas une erreur.
 *
 * Lancer: php artisan db:seed --class=McpCommunityMarketingWorkflowSeeder
 */
class McpCommunityMarketingWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        $workflows = [

            // ══════════════════ MARKETING INTELLIGENCE ══════════════════
            [
                'slug' => 'marketing-overview',
                'name' => 'Vue d\'ensemble marketing',
                'trigger_description' => "L'utilisateur demande un état des lieux global de son marketing (« comment va mon marketing », « fais-moi un point marketing »).",
                'steps' => [
                    ['capability' => 'playbook_marketing-ads-account-summary', 'label' => 'Synthèse des comptes publicitaires actifs', 'optional' => true],
                    ['capability' => 'playbook_email_campaign_performance', 'label' => 'Performance de la dernière campagne email', 'optional' => true],
                    ['capability' => 'playbook_marketing-traffic-overview', 'label' => 'Vue d\'ensemble du trafic', 'optional' => false],
                ],
            ],
            [
                'slug' => 'marketing-channel-comparison',
                'name' => 'Comparaison des canaux marketing',
                'trigger_description' => "L'utilisateur veut comparer l'efficacité de ses différents canaux (payant, email, organique).",
                'steps' => [
                    ['capability' => 'playbook_marketing-ads-account-summary', 'label' => 'Performance des canaux payants', 'optional' => true],
                    ['capability' => 'playbook_marketing-traffic-sources', 'label' => 'Répartition du trafic par canal', 'optional' => false],
                    ['capability' => 'playbook_email_campaign_performance', 'label' => 'Performance du canal email', 'optional' => true],
                ],
            ],
            [
                'slug' => 'marketing-attribution-analysis',
                'name' => 'Analyse d\'attribution',
                'trigger_description' => "L'utilisateur veut savoir quel canal génère le plus de conversions.",
                'steps' => [
                    ['capability' => 'playbook_marketing-traffic-sources', 'label' => 'Sessions par canal', 'optional' => false],
                    ['capability' => 'playbook_marketing-conversions', 'label' => 'Conversions sur la même période', 'optional' => false],
                ],
            ],
            [
                'slug' => 'marketing-audience-analysis',
                'name' => 'Analyse d\'audience',
                'trigger_description' => "L'utilisateur veut mieux connaître qui visite son site ou qui compose son audience email.",
                'steps' => [
                    ['capability' => 'playbook_marketing-audience-demographics', 'label' => 'Démographie des visiteurs du site', 'optional' => false],
                    ['capability' => 'playbook_marketing-audience-list', 'label' => 'Composition de l\'audience email', 'optional' => true],
                ],
            ],
            [
                'slug' => 'marketing-customer-segments',
                'name' => 'Segments clients email',
                'trigger_description' => "L'utilisateur veut analyser les segments ou listes de son audience email.",
                'steps' => [
                    ['capability' => 'playbook_marketing-audience-list', 'label' => 'Listes/audiences existantes', 'optional' => false],
                    ['capability' => 'playbook_marketing-get-subscriber', 'label' => 'Détail d\'un abonné si nommé', 'optional' => true],
                ],
            ],
            [
                'slug' => 'marketing-conversion-analysis',
                'name' => 'Analyse des conversions',
                'trigger_description' => "L'utilisateur veut comprendre ses conversions récentes en détail.",
                'steps' => [
                    ['capability' => 'playbook_marketing-conversions', 'label' => 'Conversions sur la période demandée', 'optional' => false],
                ],
            ],
            [
                'slug' => 'marketing-funnel-analysis',
                'name' => 'Analyse d\'entonnoir',
                'trigger_description' => "L'utilisateur veut comprendre où les visiteurs abandonnent avant de convertir.",
                'steps' => [
                    ['capability' => 'playbook_marketing-traffic-overview', 'label' => 'Volume de trafic entrant', 'optional' => false],
                    ['capability' => 'playbook_marketing-conversions', 'label' => 'Volume de conversions pour calculer le taux de perte', 'optional' => false],
                ],
                // ⚠️ Approximation : aucun outil n'expose un entonnoir étape par étape,
                // le calcul de perte se limite à entrée vs conversion finale.
            ],
            [
                'slug' => 'marketing-email-performance',
                'name' => 'Performance email',
                'trigger_description' => "L'utilisateur demande la performance de ses campagnes email.",
                'steps' => [
                    ['capability' => 'playbook_email_campaign_performance', 'label' => 'Performance de campagne(s) email', 'optional' => false],
                    ['capability' => 'playbook_marketing-list-campaigns', 'label' => 'Liste des campagnes récentes si aucune n\'est nommée', 'optional' => true],
                ],
            ],
            [
                'slug' => 'marketing-social-performance',
                'name' => 'Performance sociale',
                'trigger_description' => "L'utilisateur demande la performance de ses publications sur les réseaux sociaux.",
                'steps' => [
                    ['capability' => 'playbook_community-post-analytics', 'label' => 'Performance des publications récentes', 'optional' => false],
                ],
            ],
            [
                'slug' => 'marketing-paid-ads-analysis',
                'name' => 'Analyse publicitaire payante',
                'trigger_description' => "L'utilisateur veut une analyse détaillée de ses publicités payantes.",
                'steps' => [
                    ['capability' => 'playbook_marketing-ads-account-summary', 'label' => 'Synthèse du compte publicitaire', 'optional' => false],
                    ['capability' => 'playbook_ads_campaign_performance', 'label' => 'Performance par campagne', 'optional' => false],
                    ['capability' => 'playbook_marketing-keyword-performance', 'label' => 'Performance par mot-clé si Google Ads', 'optional' => true],
                ],
            ],
            [
                'slug' => 'marketing-budget-recommendations',
                'name' => 'Recommandations budgétaires',
                'trigger_description' => "L'utilisateur demande comment répartir ou ajuster son budget publicitaire.",
                'steps' => [
                    ['capability' => 'playbook_marketing-ads-account-summary', 'label' => 'Dépense et résultats actuels par compte', 'optional' => false],
                    ['capability' => 'playbook_ads_campaign_performance', 'label' => 'Performance détaillée par campagne pour arbitrer', 'optional' => false],
                ],
                // ⚠️ Recommandation = raisonnement du LLM sur la donnée récoltée,
                // aucun outil de recommandation automatique n'existe.
            ],
            [
                'slug' => 'marketing-monthly-report',
                'name' => 'Rapport marketing mensuel',
                'trigger_description' => "L'utilisateur demande un rapport marketing complet sur le mois.",
                'steps' => [
                    ['capability' => 'playbook_marketing-ads-account-summary', 'label' => 'Publicité payante du mois', 'optional' => true],
                    ['capability' => 'playbook_email_campaign_performance', 'label' => 'Email du mois', 'optional' => true],
                    ['capability' => 'playbook_marketing-traffic-overview', 'label' => 'Trafic du mois', 'optional' => false],
                    ['capability' => 'playbook_marketing-conversions', 'label' => 'Conversions du mois', 'optional' => false],
                ],
            ],

            // ══════════════════ COMMUNITY MANAGEMENT ══════════════════
            [
                'slug' => 'community-overview',
                'name' => 'Vue d\'ensemble présence sociale',
                'trigger_description' => "L'utilisateur demande un état des lieux de sa présence sur les réseaux sociaux.",
                'steps' => [
                    ['capability' => 'playbook_community-list-profiles', 'label' => 'Profils sociaux connectés', 'optional' => false],
                    ['capability' => 'playbook_community-list-scheduled', 'label' => 'Publications à venir', 'optional' => true],
                ],
            ],
            [
                'slug' => 'community-content-performance',
                'name' => 'Performance du contenu social',
                'trigger_description' => "L'utilisateur veut savoir quel contenu social a le mieux performé récemment.",
                'steps' => [
                    ['capability' => 'playbook_community-post-analytics', 'label' => 'Engagement des publications récentes', 'optional' => false],
                ],
            ],
            [
                'slug' => 'community-best-posts',
                'name' => 'Meilleures publications',
                'trigger_description' => "L'utilisateur demande quelles publications ont le mieux marché.",
                'steps' => [
                    ['capability' => 'playbook_community-post-analytics', 'label' => 'Engagement des publications récentes, trié par performance', 'optional' => false],
                ],
            ],
            [
                'slug' => 'community-content-ideas',
                'name' => 'Idées de contenu',
                'trigger_description' => "L'utilisateur cherche des idées de sujets ou de publications à créer.",
                'steps' => [
                    ['capability' => 'playbook_seo-keyword-opportunities', 'label' => 'Mots-clés/sujets à fort potentiel non couverts', 'optional' => false],
                ],
            ],
            [
                'slug' => 'community-editorial-calendar',
                'name' => 'Calendrier éditorial',
                'trigger_description' => "L'utilisateur veut voir ou organiser son calendrier de publication social.",
                'steps' => [
                    ['capability' => 'playbook_community-list-scheduled', 'label' => 'Publications déjà programmées', 'optional' => false],
                    ['capability' => 'playbook_social_schedule_post', 'label' => 'Programmer une nouvelle publication si demandé', 'optional' => true],
                ],
            ],
            [
                'slug' => 'community-faq-detection',
                'name' => 'Détection des questions fréquentes',
                'trigger_description' => "L'utilisateur veut savoir quelles questions reviennent le plus souvent auprès de ses visiteurs.",
                'steps' => [
                    ['capability' => 'playbook_support-conversation-search', 'label' => 'Rechercher dans l\'historique des conversations', 'optional' => false],
                    ['capability' => 'playbook_support-unanswered-count', 'label' => 'Volume de questions restées sans réponse', 'optional' => true],
                ],
                // Réutilise ELChat Platform plutôt que les réseaux sociaux :
                // les vraies questions fréquentes sont dans les conversations,
                // pas dans les commentaires (non lisibles aujourd'hui).
            ],
            [
                'slug' => 'community-engagement-report',
                'name' => 'Rapport d\'engagement social',
                'trigger_description' => "L'utilisateur demande un rapport d'engagement sur ses réseaux sociaux.",
                'steps' => [
                    ['capability' => 'playbook_community-post-analytics', 'label' => 'Engagement des publications sur la période', 'optional' => false],
                    ['capability' => 'playbook_community-list-profiles', 'label' => 'Profils concernés', 'optional' => true],
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
