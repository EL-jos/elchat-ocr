<?php

namespace Database\Seeders;

use App\Domain\MCP\Connectors\AsanaConnector;
use App\Domain\MCP\Connectors\BrevoConnector;
use App\Domain\MCP\Connectors\BufferConnector;
use App\Domain\MCP\Connectors\ClickUpConnector;
use App\Domain\MCP\Connectors\DataForSeoConnector;
use App\Domain\MCP\Connectors\ElchatPlatformConnector;
use App\Domain\MCP\Connectors\GoogleAdsConnector;
use App\Domain\MCP\Connectors\GoogleAnalyticsConnector;
use App\Domain\MCP\Connectors\GoogleCalendarConnector;
use App\Domain\MCP\Connectors\GoogleDriveConnector;
use App\Domain\MCP\Connectors\GoogleSearchConsoleConnector;
use App\Domain\MCP\Connectors\HootsuiteConnector;
use App\Domain\MCP\Connectors\HubSpotConnector;
use App\Domain\MCP\Connectors\KlaviyoConnector;
use App\Domain\MCP\Connectors\MailchimpConnector;
use App\Domain\MCP\Connectors\MetaAdsConnector;
use App\Domain\MCP\Connectors\MicrosoftTeamsConnector;
use App\Domain\MCP\Connectors\Microsoft365Connector;
use App\Domain\MCP\Connectors\NotionConnector;
use App\Domain\MCP\Connectors\OdooConnector;
use App\Domain\MCP\Connectors\OneDriveConnector;
use App\Domain\MCP\Connectors\SalesHunterConnector;
use App\Domain\MCP\Connectors\SemrushConnector;
use App\Domain\MCP\Connectors\ShopifyConnector;
use App\Domain\MCP\Connectors\SlackConnector;
use App\Domain\MCP\Connectors\TrelloConnector;
use App\Domain\MCP\Connectors\WooCommerceConnector;
use App\Models\Mcp\McpConnector;
use Illuminate\Database\Seeder;

/**
 * Peuple le catalogue de connecteurs disponibles dans la marketplace.
 * Lancer: php artisan db:seed --class=McpConnectorSeeder
 */
class McpConnectorSeeder extends Seeder
{
    public function run(): void
    {
        McpConnector::updateOrCreate(['slug' => 'woocommerce'], [
            'name' => 'WooCommerce',
            'category' => 'e_commerce',
            'adapter_class' => WooCommerceConnector::class,
            'auth_type' => 'api_key',
            'description' => "Consultation et gestion des commandes de votre boutique WooCommerce.",
            'is_active' => true,
            'icon_url' => 'https://cdn.simpleicons.org/woocommerce/96588C',
        ]);

        McpConnector::updateOrCreate(['slug' => 'google_calendar'], [
            'name' => 'Google Calendar',
            'category' => 'calendar',
            'adapter_class' => GoogleCalendarConnector::class,
            'auth_type' => 'oauth2',
            'description' => "Vérification de disponibilités et prise de rendez-vous automatisée.",
            'is_active' => true,
            'icon_url' => 'https://cdn.simpleicons.org/googlecalendar',
        ]);

        // Prochains connecteurs : ajoutez une entrée ici + la classe associée.
        McpConnector::updateOrCreate(['slug' => 'hubspot'], [
            'name' => 'HubSpot',
            'category' => 'crm',
            'adapter_class' => HubSpotConnector::class,
            'auth_type' => 'api_key',
            'description' => "Contacts, opportunités, tickets et tâches de votre CRM HubSpot.",
            'is_active' => true,
            'icon_url' => 'https://cdn.simpleicons.org/hubspot/FF7A59',
        ]);

        McpConnector::updateOrCreate(['slug' => 'shopify'], [
            'name' => 'Shopify', 'category' => 'e_commerce', 'auth_type' => 'api_key',
            'adapter_class' => ShopifyConnector::class,
            'description' => 'Produits, panier et commandes de votre boutique Shopify.',
            'icon_url' => 'https://cdn.simpleicons.org/shopify/95BF47', 'is_active' => true,
        ]);
        McpConnector::updateOrCreate(['slug' => 'google_drive'], [
            'name' => 'Google Drive', 'category' => 'storage', 'auth_type' => 'oauth2',
            'adapter_class' => GoogleDriveConnector::class,
            'description' => 'Recherche et partage de documents sur Google Drive.',
            'icon_url' => 'https://cdn.simpleicons.org/googledrive/4285F4', 'is_active' => true,
        ]);
        McpConnector::updateOrCreate(['slug' => 'onedrive'], [
            'name' => 'OneDrive', 'category' => 'storage', 'auth_type' => 'oauth2',
            'adapter_class' => OneDriveConnector::class,
            'description' => 'Recherche et partage de documents sur OneDrive.',
            'icon_url' => 'https://api.iconify.design/logos:microsoft-onedrive.svg', 'is_active' => true,
        ]);
        McpConnector::updateOrCreate(['slug' => 'slack'], [
            'name' => 'Slack', 'category' => 'communication', 'auth_type' => 'api_key',
            'adapter_class' => SlackConnector::class,
            'description' => "Notifie votre équipe directement dans Slack.",
            'icon_url' => 'https://cdn.jsdelivr.net/gh/devicons/devicon/icons/slack/slack-original.svg', 'is_active' => true,
        ]);
        McpConnector::updateOrCreate(['slug' => 'microsoft_teams'], [
            'name' => 'Microsoft Teams', 'category' => 'communication', 'auth_type' => 'api_key',
            'adapter_class' => MicrosoftTeamsConnector::class,
            'description' => "Notifie votre équipe directement dans Teams.",
            'icon_url' => 'https://api.iconify.design/logos:microsoft-teams.svg', 'is_active' => true,
        ]);
        McpConnector::updateOrCreate(['slug' => 'microsoft_365'], [
            'name' => 'Microsoft 365', 'category' => 'microsoft_365', 'auth_type' => 'oauth2',
            'adapter_class' => Microsoft365Connector::class,
            'description' => 'Documents OneDrive/SharePoint, Excel, Outlook et Teams via Microsoft Graph, avec permissions déléguées et confirmation des actions sensibles.',
            'icon_url' => 'https://upload.wikimedia.org/wikipedia/commons/0/0e/Microsoft_365_%282022%29.svg?utm_source=commons.wikimedia.org&utm_campaign=index&utm_content=original', 'is_active' => true,
        ]);
        McpConnector::updateOrCreate(['slug' => 'asana'], [
            'name' => 'Asana', 'category' => 'project_management', 'auth_type' => 'api_key',
            'adapter_class' => AsanaConnector::class,
            'description' => 'Tâches et suivi de projet dans Asana.',
            'icon_url' => 'https://cdn.simpleicons.org/asana/F06A6A', 'is_active' => true,
        ]);
        McpConnector::updateOrCreate(['slug' => 'notion'], [
            'name' => 'Notion', 'category' => 'documentation', 'auth_type' => 'api_key',
            'adapter_class' => NotionConnector::class,
            'description' => 'Pages et base de connaissances Notion.',
            'icon_url' => 'https://cdn.simpleicons.org/notion', 'is_active' => true,
        ]);

        McpConnector::updateOrCreate(['slug' => 'odoo'], [
            'name' => 'Odoo', 'category' => 'erp', 'auth_type' => 'api_key',
            'adapter_class' => OdooConnector::class,
            'description' => "CRM, ventes, stock, comptabilité, support, rendez-vous, projets et plus — selon les modules Odoo installés sur votre instance.",
            'icon_url' => 'https://cdn.simpleicons.org/odoo/714B67', 'is_active' => true,
        ]);

        McpConnector::updateOrCreate(['slug' => 'elchat_platform'], [
            'name' => 'ELChat Platform', 'category' => 'internal', 'auth_type' => 'internal', // 🆕 nouveau type, aucune donnée tierce
            'adapter_class' => ElchatPlatformConnector::class,
            'description' => "Copilote interne : conversations, visiteurs, statistiques et pilotage de vos agents/workflows ELChat.",
            'icon_url' => 'https://elchat.io/assets/images/logo.svg', 'is_active' => true,
        ]);

        McpConnector::updateOrCreate(['slug' => 'google_analytics'], [
            'name' => 'Google Analytics', 'category' => 'analytics', 'auth_type' => 'oauth2',
            'adapter_class' => GoogleAnalyticsConnector::class,
            'description' => "Trafic, sources d'acquisition, conversions et audience de votre site (GA4).",
            'icon_url' => 'https://cdn.simpleicons.org/googleanalytics/E37400', 'is_active' => true,
        ]);

        McpConnector::updateOrCreate(['slug' => 'google_search_console'], [
            'name' => 'Google Search Console', 'category' => 'seo', 'auth_type' => 'oauth2',
            'adapter_class' => GoogleSearchConsoleConnector::class,
            'description' => "Performance de recherche Google (clics, impressions, positions) et statut d'indexation de vos pages.",
            'icon_url' => 'https://cdn.simpleicons.org/googlesearchconsole/458CF5', 'is_active' => true,
        ]);

        McpConnector::updateOrCreate(['slug' => 'google_ads'], [
            'name' => 'Google Ads', 'category' => 'advertising', 'auth_type' => 'oauth2',
            'adapter_class' => GoogleAdsConnector::class,
            'description' => "Performance de vos campagnes Google Ads, et pilotage (pause, budget) sous confirmation d'un conseiller.",
            'icon_url' => 'https://cdn.simpleicons.org/googleads/4285F4', 'is_active' => true,
        ]);

        McpConnector::updateOrCreate(['slug' => 'meta_ads'], [
            'name' => 'Meta Ads', 'category' => 'advertising', 'auth_type' => 'oauth2',
            'adapter_class' => MetaAdsConnector::class,
            'description' => "Performance de vos campagnes Facebook/Instagram Ads, et pilotage (pause, budget) sous confirmation d'un conseiller.",
            'icon_url' => 'https://cdn.simpleicons.org/meta/0866FF', 'is_active' => true,
        ]);

        McpConnector::updateOrCreate(['slug' => 'semrush'], [
            'name' => 'Semrush', 'category' => 'seo', 'auth_type' => 'api_key',
            'adapter_class' => SemrushConnector::class,
            'description' => "Analyse SEO concurrentielle : mots-clés, trafic organique, backlinks et concurrents.",
            'icon_url' => 'https://cdn.simpleicons.org/semrush/FF642D', 'is_active' => true,
        ]);

        McpConnector::updateOrCreate(['slug' => 'mailchimp'], [
            'name' => 'Mailchimp', 'category' => 'email_marketing', 'auth_type' => 'api_key',
            'adapter_class' => MailchimpConnector::class,
            'description' => "Audiences, campagnes et inscription à votre newsletter Mailchimp.",
            'icon_url' => 'https://cdn.simpleicons.org/mailchimp/FFE01B', 'is_active' => true,
        ]);

        McpConnector::updateOrCreate(['slug' => 'klaviyo'], [
            'name' => 'Klaviyo', 'category' => 'email_marketing', 'auth_type' => 'api_key',
            'adapter_class' => KlaviyoConnector::class,
            'description' => "Listes, campagnes et inscription à vos listes Klaviyo.",
            'icon_url' => 'https://cdn.simpleicons.org/klaviyo/222222', 'is_active' => true,
        ]);

        McpConnector::updateOrCreate(['slug' => 'brevo'], [
            'name' => 'Brevo', 'category' => 'email_marketing', 'auth_type' => 'api_key',
            'adapter_class' => BrevoConnector::class,
            'description' => "Listes de contacts, campagnes et inscription à vos listes Brevo.",
            'icon_url' => 'https://cdn.simpleicons.org/brevo/0B996E', 'is_active' => true,
        ]);

        McpConnector::updateOrCreate(['slug' => 'hootsuite'], [
            'name' => 'Hootsuite', 'category' => 'social_media', 'auth_type' => 'oauth2',
            'adapter_class' => HootsuiteConnector::class,
            'description' => "Programmation et suivi de vos publications sur les réseaux sociaux via Hootsuite.",
            'icon_url' => 'https://cdn.simpleicons.org/hootsuite/000000', 'is_active' => true,
        ]);

        McpConnector::updateOrCreate(['slug' => 'buffer'], [
            'name' => 'Buffer', 'category' => 'social_media', 'auth_type' => 'oauth2',
            'adapter_class' => BufferConnector::class,
            'description' => "Programmation et suivi de vos publications sur les réseaux sociaux via Buffer.",
            'icon_url' => 'https://cdn.simpleicons.org/buffer/231F20', 'is_active' => true,
        ]);

        McpConnector::updateOrCreate(['slug' => 'sales_hunter'], [
            'name' => 'Sales Hunter (interne)', 'category' => 'internal', 'auth_type' => 'internal',
            'adapter_class' => SalesHunterConnector::class,
            'description' => "Outils internes de prospection (analyse de site, statut, rédaction) utilisés par l'agent AI Sales Hunter.",
            'icon_url' => 'https://elchat.io/assets/images/logo.svg', 'is_active' => true,
        ]);

        McpConnector::updateOrCreate(['slug' => 'clickup'], [
            'name' => 'ClickUp', 'category' => 'project_management', 'auth_type' => 'api_key',
            'adapter_class' => ClickUpConnector::class,
            'description' => "Tâches, listes et commentaires de votre espace ClickUp.",
            'icon_url' => 'https://cdn.simpleicons.org/clickup/7B68EE', 'is_active' => true,
        ]);

        McpConnector::updateOrCreate(['slug' => 'trello'], [
            'name' => 'Trello', 'category' => 'project_management', 'auth_type' => 'api_key',
            'adapter_class' => TrelloConnector::class,
            'description' => "Tableaux, cartes et commentaires de votre espace Trello.",
            'icon_url' => 'https://cdn.simpleicons.org/trello/0052CC', 'is_active' => true,
        ]);

        McpConnector::updateOrCreate(['slug' => 'dataforseo'], [
            'name' => 'DataForSEO', 'category' => 'seo', 'auth_type' => 'api_key',
            'adapter_class' => DataForSeoConnector::class,
            'description' => "Analyse SEO/SEM : mots-clés, trafic organique, backlinks, concurrents et résultats Google en direct.",
            'icon_url' => 'https://dataforseo.com/favicon.ico', 'is_active' => true,
        ]);
    }
}
