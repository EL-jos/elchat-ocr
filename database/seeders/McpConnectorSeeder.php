<?php

namespace Database\Seeders;

use App\Domain\MCP\Connectors\GoogleCalendarConnector;
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
        ]);

        McpConnector::updateOrCreate(['slug' => 'google_calendar'], [
            'name' => 'Google Calendar',
            'category' => 'calendar',
            'adapter_class' => GoogleCalendarConnector::class,
            'auth_type' => 'oauth2',
            'description' => "Vérification de disponibilités et prise de rendez-vous automatisée.",
            'is_active' => true,
        ]);

        // Prochains connecteurs : ajoutez une entrée ici + la classe associée.
        // McpConnector::updateOrCreate(['slug' => 'stripe'], [...]);
    }
}
