<?php

namespace Database\Seeders;

use App\Domain\MCP\Connectors\AsanaConnector;
use App\Domain\MCP\Connectors\GoogleCalendarConnector;
use App\Domain\MCP\Connectors\GoogleDriveConnector;
use App\Domain\MCP\Connectors\HubSpotConnector;
use App\Domain\MCP\Connectors\MicrosoftTeamsConnector;
use App\Domain\MCP\Connectors\NotionConnector;
use App\Domain\MCP\Connectors\OdooConnector;
use App\Domain\MCP\Connectors\OneDriveConnector;
use App\Domain\MCP\Connectors\ShopifyConnector;
use App\Domain\MCP\Connectors\SlackConnector;
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
    }
}
