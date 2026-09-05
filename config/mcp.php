<?php

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
use App\Domain\MCP\Connectors\JiraConnector;
use App\Domain\MCP\Connectors\MailchimpConnector;
use App\Domain\MCP\Connectors\MetaAdsConnector;
use App\Domain\MCP\Connectors\MicrosoftTeamsConnector;
use App\Domain\MCP\Connectors\Microsoft365Connector;
use App\Domain\MCP\Connectors\MondayConnector;
use App\Domain\MCP\Connectors\NotionConnector;
use App\Domain\MCP\Connectors\OdooConnector;
use App\Domain\MCP\Connectors\OneDriveConnector;
use App\Domain\MCP\Connectors\SalesHunterConnector;
use App\Domain\MCP\Connectors\SemrushConnector;
use App\Domain\MCP\Connectors\ShopifyConnector;
use App\Domain\MCP\Connectors\SlackConnector;
use App\Domain\MCP\Connectors\TrelloConnector;
use App\Domain\MCP\Connectors\WooCommerceConnector;

return [
    /*
     * Active/désactive complètement le système MCP au niveau applicatif.
     * Utile comme kill switch en production sans déployer.
     */
    'enabled' => env('MCP_ENABLED', true),

    /*
     * Génération unifiée : le modèle de conversation reçoit les outils MCP
     * autorisés avec le contexte RAG et choisit directement entre une réponse
     * texte et un tool_call. Le kill switch permet un retour immédiat au
     * comportement historique en cas de régression provider.
     */
    'unified_tool_calling' => env('MCP_UNIFIED_TOOL_CALLING', true),

    /*
     * Étend la décision unifiée aux sites qui ont plusieurs agents actifs.
     * Désactiver cette option restaure uniquement le routage historique
     * multi-agent, sans désactiver le flux unifié pour zéro ou un agent.
     */
    'unified_multi_agent_tool_calling' => env('MCP_UNIFIED_MULTI_AGENT_TOOL_CALLING', true),

    /*
     * Repli de sécurité uniquement lorsqu'une génération unifiée échoue avant
     * toute exécution d'outil. Il ne s'active jamais après un effet métier,
     * afin d'éviter toute double action.
     */
    'unified_multi_agent_legacy_fallback' => env('MCP_UNIFIED_MULTI_AGENT_LEGACY_FALLBACK', true),

    'llm' => [
        // Même clé que ChatService::callLLM (OPENROUTER_API_KEY) : un seul
        // fournisseur LLM pour toute l'application, y compris MCP.
        'api_key' => env('OPENROUTER_API_KEY'),
        // Compatibilité legacy : la source de vérité est config/llm.php.
        'model' => env('MCP_LLM_MODEL'),
        'fallback_model' => env('MCP_LLM_FALLBACK_MODEL'),
        'max_response_bytes' => (int) env('MCP_LLM_MAX_RESPONSE_BYTES', 4194304),
        'max_json_chars' => (int) env('MCP_LLM_MAX_JSON_CHARS', 1048576),
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

        'shopify' => ['class' => ShopifyConnector::class],

        'google_drive' => [
            'class' => GoogleDriveConnector::class,
            'client_id' => env('GOOGLE_CLIENT_ID'),
            'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        ],
        'onedrive' => [
            'class' => OneDriveConnector::class,
            'client_id' => env('MICROSOFT_CLIENT_ID'),
            'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
            'tenant' => env('MICROSOFT_TENANT', 'common'),
        ],
        'slack' => ['class' => SlackConnector::class],
        'microsoft_teams' => ['class' => MicrosoftTeamsConnector::class],
        'microsoft_365' => [
            'class' => Microsoft365Connector::class,
            'client_id' => env('MICROSOFT_CLIENT_ID'),
            'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
            'tenant' => env('MICROSOFT_TENANT', 'common'),
        ],
        'asana' => ['class' => AsanaConnector::class],
        'notion' => ['class' => NotionConnector::class],

        'jira' => [
            'class' => JiraConnector::class,
            'client_id' => env('JIRA_CLIENT_ID'),
            'client_secret' => env('JIRA_CLIENT_SECRET'),
            'redirect_uri' => env('JIRA_REDIRECT_URI'),
        ],
        'monday' => [
            'class' => MondayConnector::class,
            'client_id' => env('MONDAY_CLIENT_ID'),
            'client_secret' => env('MONDAY_CLIENT_SECRET'),
            'redirect_uri' => env('MONDAY_REDIRECT_URI'),
            // Legacy OAuth2 remains the default for existing monday apps.
            // New OAuth 2.1 apps can opt in with MONDAY_OAUTH_USE_PKCE=true
            // and the token endpoint documented below.
            'token_endpoint' => env('MONDAY_OAUTH_TOKEN_ENDPOINT', 'https://auth.monday.com/oauth2/token'),
            'use_pkce' => filter_var(env('MONDAY_OAUTH_USE_PKCE', false), FILTER_VALIDATE_BOOL),
        ],

        'odoo' => ['class' => OdooConnector::class],

        'elchat_platform' => ['class' => ElchatPlatformConnector::class],

        // ── 🆕 Analytics & Ads ──────────────────────────────────────────

        // Réutilise le même client OAuth Google que google_calendar/drive :
        // un seul projet Google Cloud, plusieurs scopes autorisés dessus.
        // Le scope demandé (voir MCPConnectorController::oauthRedirect)
        // détermine ce à quoi le jeton donne accès, pas le client_id.
        'google_analytics' => [
            'class' => GoogleAnalyticsConnector::class,
            'client_id' => env('GOOGLE_CLIENT_ID'),
            'client_secret' => env('GOOGLE_CLIENT_SECRET'),
            'redirect_uri' => env('GOOGLE_ANALYTICS_REDIRECT_URI'),
        ],
        'google_search_console' => [
            'class' => GoogleSearchConsoleConnector::class,
            'client_id' => env('GOOGLE_CLIENT_ID'),
            'client_secret' => env('GOOGLE_CLIENT_SECRET'),
            'redirect_uri' => env('GOOGLE_SEARCH_CONSOLE_REDIRECT_URI'),
        ],
        'google_ads' => [
            'class' => GoogleAdsConnector::class,
            'client_id' => env('GOOGLE_CLIENT_ID'),
            'client_secret' => env('GOOGLE_CLIENT_SECRET'),
            // Jeton développeur attribué par Google au Manager Account (MCC)
            // ELChat — une seule valeur pour toute la plateforme, jamais par
            // site. Demande d'accès "Standard" nécessaire côté Google Ads
            // pour dépasser le quota "Test" (15 comptes clients max).
            'developer_token' => env('GOOGLE_ADS_DEVELOPER_TOKEN'),
        ],

        // Facebook Marketing API — app dédiée distincte du client OAuth
        // Google, avec son propre app_id/app_secret enregistrés sur
        // developers.facebook.com (produit "Marketing API").
        'meta_ads' => [
            'class' => MetaAdsConnector::class,
            'app_id' => env('META_ADS_APP_ID'),
            'app_secret' => env('META_ADS_APP_SECRET'),
        ],

        // Clé API statique (Semrush > Profil > API Units), pas d'OAuth.
        'semrush' => ['class' => SemrushConnector::class],

        // Clé API statique (le datacenter usXX fait partie de la clé elle-même,
        // voir MailchimpConnector::client()) — rien à configurer ici.
        'mailchimp' => ['class' => MailchimpConnector::class],
        // Clé API privée statique.
        'klaviyo' => ['class' => KlaviyoConnector::class],

        // Clé API statique.
        'brevo' => ['class' => BrevoConnector::class],

        // OAuth2 — app créée sur https://hootsuite.dev (Developer Portal), scope
        // par défaut de l'app (pas de paramètre 'scope' dans l'URL d'autorisation
        // Hootsuite : le périmètre est fixé au niveau de l'app elle-même côté
        // portail développeur, à configurer en lecture+écriture messages/profiles).
        'hootsuite' => [
            'class' => HootsuiteConnector::class,
            'client_id' => env('HOOTSUITE_CLIENT_ID'),
            'client_secret' => env('HOOTSUITE_CLIENT_SECRET'),
        ],

        // OAuth2 — app créée sur https://buffer.com/developers/apps. Jetons Buffer
        // v1 sans expiration documentée : pas de refresh_token à gérer.
        'buffer' => [
            'class' => BufferConnector::class,
            'client_id' => env('BUFFER_CLIENT_ID'),
            'client_secret' => env('BUFFER_CLIENT_SECRET'),
        ],

        'sales_hunter' => ['class' => SalesHunterConnector::class], // connecteur interne, pas de credentials

        'clickup' => ['class' => ClickUpConnector::class],
        'trello' => ['class' => TrelloConnector::class],
        'dataforseo' => ['class' => DataForSeoConnector::class],
    ],

    'orchestrator' => [
        'max_hops' => env('MCP_MAX_HOPS', 12),
        // Même plafond par défaut que l'orchestrateur historique ; une
        // valeur dédiée permet de réduire le budget du nouveau flux sans
        // modifier le comportement multi-agent.
        'unified_max_hops' => env('MCP_UNIFIED_MAX_HOPS', env('MCP_MAX_HOPS', 12)),
    ],
];
