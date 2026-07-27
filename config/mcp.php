<?php

use App\Domain\MCP\Connectors\GoogleCalendarConnector;
use App\Domain\MCP\Connectors\HubSpotConnector;
use App\Domain\MCP\Connectors\WooCommerceConnector;

return [
    /*
     * Active/désactive complètement le système MCP au niveau applicatif.
     * Utile comme kill switch en production sans déployer.
     */
    'enabled' => env('MCP_ENABLED', true),

    'llm' => [
        // Même clé que ChatService::callLLM (OPENROUTER_API_KEY) : un seul
        // fournisseur LLM pour toute l'application, y compris MCP.
        'api_key' => env('OPENROUTER_API_KEY'),
        'model' => env('MCP_LLM_MODEL', 'openai/gpt-4.1-mini'),
    ],

    /*
     * Registre statique des connecteurs disponibles côté code. Ajouter un
     * connecteur = une ligne ici + la classe correspondante dans
     * app/Domain/MCP/Connectors. C'est le SEUL endroit à modifier pour
     * brancher un nouveau connecteur (avec la ligne correspondante dans la
     * table mcp_connectors, gérée depuis l'admin ou un seeder).
     */
    'connectors' => [
        'woocommerce' => [
            'class' => WooCommerceConnector::class,
        ],
        'google_calendar' => [
            'class' => GoogleCalendarConnector::class,
            'client_id' => env('GOOGLE_CLIENT_ID'),
            'client_secret' => env('GOOGLE_CLIENT_SECRET'),
            'redirect_uri' => env('GOOGLE_CALENDAR_REDIRECT_URI'),
        ],
        'hubspot' => [
            'class' => HubSpotConnector::class,
        ],

        // Prochains connecteurs (exemple, à activer quand implémentés) :
        // 'stripe' => ['class' => \App\Domain\MCP\Connectors\StripeConnector::class],
        // 'hubspot' => ['class' => \App\Domain\MCP\Connectors\HubspotConnector::class],
    ],

    'orchestrator' => [
        'max_hops' => env('MCP_MAX_HOPS', 8),
    ],
];
