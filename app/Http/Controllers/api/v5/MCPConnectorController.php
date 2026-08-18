<?php

namespace App\Http\Controllers\api\v5;

use App\Domain\MCP\Contracts\ProvidesSiteScopedTools;
use App\Domain\MCP\Registry\ConnectorRegistry;
use App\Domain\MCP\Security\CredentialVault;
use App\Domain\MCP\Security\PermissionEngine;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\Mcp\McpConnector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * API du "marketplace de connecteurs" côté admin site (consommée par le
 * module Angular mcp/connector-marketplace).
 */
class MCPConnectorController extends Controller
{
    private const GOOGLE_OAUTH_CONNECTORS = [
        'google_calendar',
        'google_drive',
        'google_analytics',
        'google_search_console',
        'google_ads',
    ];

    public function __construct(
        private readonly CredentialVault $vault,
        private readonly PermissionEngine $permissions, // 🆕
        private readonly ConnectorRegistry $registry,   // 🆕
    )
    {
    }

    /**
     * Liste tous les connecteurs disponibles + leur statut d'activation
     * pour le site courant.
     */
    public function index(Request $request, Site $site)
    {
        $connectors = McpConnector::where('is_active', true)
            ->with(['siteConnectors' => fn ($q) => $q->where('site_id', $site->id)])
            ->get()
            ->map(function (McpConnector $connector) {
                $activation = $connector->siteConnectors->first();

                return [
                    'slug' => $connector->slug,
                    'name' => $connector->name,
                    'category' => $connector->category,
                    'auth_type' => $connector->auth_type,
                    'icon_url' => $connector->icon_url,
                    'description' => $connector->description,
                    'status' => $activation->status ?? 'not_connected',
                    'connected_at' => $activation->connected_at ?? null,
                ];
            });

        return response()->json(['data' => $connectors]);
    }

    /**
     * Active un connecteur à identifiants statiques (API key/secret, ex:
     * WooCommerce). Pour l'OAuth2 (Google Calendar...), voir
     * oauthRedirect/oauthCallback ci-dessous.
     */
    public function activateWithApiKey(Request $request, Site $site, string $slug)
    {
        $validated = $request->validate([
            'credentials' => ['present', 'array'], // 🆕 'present' au lieu de 'required' : autorise un tableau vide (connecteurs internes)
            'settings' => ['array'],
        ]);

        //$connector = McpConnector::where('slug', $slug)->where('auth_type', 'api_key')->firstOrFail();

        $connector = McpConnector::where('slug', $slug)->firstOrFail();

        if (! in_array($connector->auth_type, ['api_key', 'internal'])) {
            abort(400, 'Ce connecteur ne peut pas être activé via cette route.');
        }

        $this->vault->store($site, $slug, $validated['credentials'], $validated['settings'] ?? []);

        // 🆕 Pré-remplit mcp_permissions avec les valeurs par défaut suggérées par
        // chaque outil (voir ToolSchema::$defaultMode/$defaultActorScope/$defaultConfirmActor).
        // N'écrase jamais une règle déjà configurée.
        if ($this->registry->has($slug)) {
            $connector = $this->registry->get($slug);
            $tools = $connector instanceof ProvidesSiteScopedTools
                ? $connector->toolsAvailableFor($this->vault->retrieve($site, $slug) ?? [])
                : $connector->listTools();
            $this->permissions->seedDefaultsIfMissing($site, $tools);
        }

        return response()->json(['status' => 'connected']);
    }

    public function deactivate(Request $request, Site $site, string $slug)
    {
        $this->vault->revoke($site, $slug);

        return response()->json(['status' => 'revoked']);
    }

    /**
     * Démarre le flux OAuth2 pour un connecteur donné (Google Calendar...).
     * Retourne l'URL d'autorisation à ouvrir côté Angular.
     */
    public function oauthRedirect(Request $request, Site $site, string $slug)
    {
        $redirectUri = route('mcp.oauth.callback', ['slug' => $slug]);
        $state = encrypt([
            'site_id' => $site->id,
            'connector' => $slug,
            // Les connecteurs Google reviennent dans la fenêtre OAuth et
            // notifient le dashboard sans remplacer la page courante.
            'response_mode' => in_array($slug, self::GOOGLE_OAUTH_CONNECTORS, true)
                ? 'post_message'
                : 'redirect',
        ]);

        if ($slug === 'meta_ads') {
            $appId = config('mcp.connectors.meta_ads.app_id');
            if (!$appId) {
                Log::error('MCP: META_ADS_APP_ID absent ou config non rechargée.');
                return response()->json(['message' => "Connecteur Meta Ads mal configuré côté serveur."], 500);
            }

            $url = 'https://www.facebook.com/v19.0/dialog/oauth?' . http_build_query([
                    'client_id' => $appId,
                    'redirect_uri' => $redirectUri,
                    'state' => $state,
                    'scope' => 'ads_read,ads_management,business_management',
                    'response_type' => 'code',
                ]);

            return response()->json(['authorization_url' => $url]);
        }

        // ── Famille Google (client_id/secret partagés, scope distinct par connecteur) ──
        $googleScopes = [
            'google_calendar' => 'https://www.googleapis.com/auth/calendar',
            'google_drive' => 'https://www.googleapis.com/auth/drive',
            'google_analytics' => 'https://www.googleapis.com/auth/analytics.readonly',
            // Deux scopes combinés dans une seule autorisation : lecture des
            // rapports (webmasters.readonly) + demande d'indexation (indexing).
            'google_search_console' => 'https://www.googleapis.com/auth/webmasters.readonly https://www.googleapis.com/auth/indexing',
            // full (pas readonly) : requis pour les mutations pause/budget de GoogleAdsConnector.
            'google_ads' => 'https://www.googleapis.com/auth/adwords',
        ];

        if (array_key_exists($slug, $googleScopes)) {
            $clientId = config("mcp.connectors.{$slug}.client_id");

            if (!$clientId) {
                Log::error("MCP: client_id Google absent ou config non rechargée pour {$slug}.");
                return response()->json(['message' => "Connecteur {$slug} mal configuré côté serveur."], 500);
            }

            $url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
                    'client_id' => $clientId,
                    'redirect_uri' => $redirectUri,
                    'response_type' => 'code',
                    'access_type' => 'offline',
                    'prompt' => 'consent',
                    'scope' => $googleScopes[$slug],
                    'state' => $state,
                ]);

            return response()->json(['authorization_url' => $url]);
        }

        if ($slug === 'onedrive') {
            $url = 'https://login.microsoftonline.com/' . config('mcp.connectors.onedrive.tenant', 'common') . '/oauth2/v2.0/authorize?' . http_build_query([
                    'client_id' => config('mcp.connectors.onedrive.client_id'), 'redirect_uri' => $redirectUri, 'response_type' => 'code',
                    'scope' => 'Files.ReadWrite offline_access', 'state' => $state,
                ]);
            return response()->json(['authorization_url' => $url]);
        }

        if ($slug === 'hootsuite') {
            $clientId = config('mcp.connectors.hootsuite.client_id');
            if (!$clientId) {
                Log::error('MCP: HOOTSUITE_CLIENT_ID absent ou config non rechargée.');
                return response()->json(['message' => "Connecteur Hootsuite mal configuré côté serveur."], 500);
            }

            $url = 'https://platform.hootsuite.com/oauth2/auth?' . http_build_query([
                    'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => $redirectUri, 'state' => $state,
                ]);
            return response()->json(['authorization_url' => $url]);
        }

        if ($slug === 'buffer') {
            $clientId = config('mcp.connectors.buffer.client_id');
            if (!$clientId) {
                Log::error('MCP: BUFFER_CLIENT_ID absent ou config non rechargée.');
                return response()->json(['message' => "Connecteur Buffer mal configuré côté serveur."], 500);
            }

            $url = 'https://bufferapp.com/oauth2/authorize?' . http_build_query([
                    'client_id' => $clientId, 'redirect_uri' => $redirectUri, 'response_type' => 'code', 'state' => $state,
                ]);
            return response()->json(['authorization_url' => $url]);
        }

        abort(404, "OAuth non supporté pour {$slug}");
    }

    public function oauthCallback(Request $request, string $slug)
    {
        $state = decrypt($request->query('state'));

        if (($state['connector'] ?? null) !== $slug) {
            abort(400, 'Le connecteur OAuth ne correspond pas à la demande initiale.');
        }

        $site = Site::findOrFail($state['site_id']);
        $usePostMessage = ($state['response_mode'] ?? null) === 'post_message'
            && in_array($slug, self::GOOGLE_OAUTH_CONNECTORS, true);
        $code = $request->query('code');
        $redirectUri = route('mcp.oauth.callback', ['slug' => $slug]);

        if ($request->filled('error')) {
            $message = $request->query('error') === 'access_denied'
                ? 'Autorisation annulée. Aucune connexion n’a été enregistrée.'
                : 'Le service n’a pas pu autoriser ce connecteur.';

            return $this->oauthResult($site, $slug, false, $message, $usePostMessage);
        }

        if (!is_string($code) || $code === '') {
            return $this->oauthResult(
                $site,
                $slug,
                false,
                'Le code d’autorisation OAuth est absent. Veuillez recommencer la connexion.',
                $usePostMessage,
            );
        }

        if ($slug === 'meta_ads') {
            $this->handleMetaOAuthCallback($site, $slug, $code, $redirectUri);
            return redirect($this->connectorUrl($site, $slug));
        }

        if ($slug === 'buffer') {
            // Buffer utilise un endpoint et des noms de champs légèrement
            // différents du flux OAuth2 standard (token.exchange, pas token) et ne
            // retourne ni refresh_token ni expires_in — jetons sans expiration
            // documentée (voir BufferConnector::authenticate()).
            try {
                $tokenResponse = \Illuminate\Support\Facades\Http::asForm()->post('https://api.bufferapp.com/1/oauth2/token.exchange', [
                    'client_id' => config('mcp.connectors.buffer.client_id'),
                    'client_secret' => config('mcp.connectors.buffer.client_secret'),
                    'redirect_uri' => $redirectUri, 'code' => $code, 'grant_type' => 'authorization_code',
                ])->throw()->json();
            } catch (\Illuminate\Http\Client\RequestException $e) {
                Log::error('MCP Buffer: échec OAuth callback', ['body' => $e->response?->body()]);
                abort(500, "Connexion Buffer impossible pour le moment.");
            }

            $this->vault->store($site, $slug, ['access_token' => $tokenResponse['access_token']]);

            if ($this->registry->has($slug)) {
                $this->permissions->seedDefaultsIfMissing($site, $this->registry->get($slug)->listTools());
            }
            return redirect($this->connectorUrl($site, $slug));
        }

        $tokenEndpoint = match (true) {
            in_array($slug, self::GOOGLE_OAUTH_CONNECTORS, true) => 'https://oauth2.googleapis.com/token',
            $slug === 'onedrive' => 'https://login.microsoftonline.com/' . config('mcp.connectors.onedrive.tenant', 'common') . '/oauth2/v2.0/token',
            $slug === 'hootsuite' => 'https://platform.hootsuite.com/oauth2/token', // grant_type standard, mêmes champs que Google/OneDrive
            default => abort(404, "OAuth non supporté pour {$slug}"),
        };

        try {
            $tokenResponse = \Illuminate\Support\Facades\Http::asForm()->post($tokenEndpoint, [
                'client_id' => config("mcp.connectors.{$slug}.client_id"),
                'client_secret' => config("mcp.connectors.{$slug}.client_secret"),
                'redirect_uri' => $redirectUri,
                'code' => $code, 'grant_type' => 'authorization_code',
            ])->throw()->json();
        } catch (\Illuminate\Http\Client\RequestException $e) {
            Log::error("MCP {$slug}: échec OAuth callback", [
                'status' => $e->response?->status(),
            ]);

            return $this->oauthResult(
                $site,
                $slug,
                false,
                'La connexion n’a pas pu être finalisée. Veuillez réessayer.',
                $usePostMessage,
            );
        }

        if (empty($tokenResponse['access_token'])) {
            Log::error("MCP {$slug}: access_token absent de la réponse OAuth.");

            return $this->oauthResult(
                $site,
                $slug,
                false,
                'Le service n’a pas retourné de jeton d’accès valide. Veuillez réessayer.',
                $usePostMessage,
            );
        }

        $credentials = [
            'access_token' => $tokenResponse['access_token'],
            'expires_at' => now()->addSeconds($tokenResponse['expires_in'] ?? 3600)->timestamp,
        ];

        if (!empty($tokenResponse['refresh_token'])) {
            $credentials['refresh_token'] = $tokenResponse['refresh_token'];
        } elseif ($existingRefreshToken = ($this->vault->retrieve($site, $slug)['refresh_token'] ?? null)) {
            // Google peut omettre le refresh_token lors d'une reconnexion :
            // on conserve alors celui qui est déjà chiffré dans le coffre.
            $credentials['refresh_token'] = $existingRefreshToken;
        }

        $this->vault->store($site, $slug, $credentials);

        // Pré-remplit mcp_permissions avec les valeurs par défaut suggérées par
        // chaque outil. N'écrase jamais une règle déjà configurée.
        if ($this->registry->has($slug)) {
            $connector = $this->registry->get($slug);
            $tools = $connector instanceof ProvidesSiteScopedTools
                ? $connector->toolsAvailableFor($this->vault->retrieve($site, $slug) ?? [])
                : $connector->listTools();
            $this->permissions->seedDefaultsIfMissing($site, $tools);
        }

        return $this->oauthResult(
            $site,
            $slug,
            true,
            'Le connecteur Google est maintenant actif.',
            $usePostMessage,
        );
    }

    /**
     * Termine un OAuth dans la popup Google. Si le callback a été ouvert
     * sans parent (favoris, navigateur restrictif...), la vue revient vers le
     * dashboard afin de conserver le comportement historique.
     */
    private function oauthResult(
        Site $site,
        string $slug,
        bool $ok,
        string $message,
        bool $usePostMessage,
    ) {
        $fallbackUrl = $this->connectorUrl($site, $slug);

        if (!$usePostMessage) {
            if ($ok) {
                return redirect($fallbackUrl);
            }

            abort(400, $message);
        }

        return response()->view('mcp.oauth-popup', [
            'ok' => $ok,
            'message' => $message,
            'slug' => $slug,
            'siteId' => (string) $site->id,
            'targetOrigin' => $this->frontendOrigin(),
            'fallbackUrl' => $fallbackUrl,
        ]);
    }

    private function connectorUrl(Site $site, string $slug): string
    {
        $dashboardUrl = rtrim((string) config('app.frontend_dashboard_url', 'https://elchat.io'), '/');

        return "{$dashboardUrl}/app/site/{$site->id}/settings/connectors?connected=" . urlencode($slug);
    }

    private function frontendOrigin(): string
    {
        $dashboardUrl = (string) config('app.frontend_dashboard_url', 'https://elchat.io');
        $parts = parse_url($dashboardUrl);

        if (!isset($parts['scheme'], $parts['host'])) {
            return 'https://elchat.io';
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return "{$parts['scheme']}://{$parts['host']}{$port}";
    }

    /**
     * 🆕 Flux OAuth Facebook, distinct du flux Google : le code s'échange en
     * GET (pas POST asForm) contre un jeton COURT (~2h), qu'il faut ensuite
     * ré-échanger immédiatement contre un jeton LONG (~60 jours) via
     * fb_exchange_token — sinon le site devrait se reconnecter toutes les 2h.
     * Les ré-échanges suivants sont gérés par MetaAdsConnector::authenticate().
     */
    private function handleMetaOAuthCallback(Site $site, string $slug, string $code, string $redirectUri): void
    {
        $appId = config('mcp.connectors.meta_ads.app_id');
        $appSecret = config('mcp.connectors.meta_ads.app_secret');

        try {
            $shortLived = \Illuminate\Support\Facades\Http::get('https://graph.facebook.com/v19.0/oauth/access_token', [
                'client_id' => $appId, 'client_secret' => $appSecret,
                'redirect_uri' => $redirectUri, 'code' => $code,
            ])->throw()->json();

            $longLived = \Illuminate\Support\Facades\Http::get('https://graph.facebook.com/v19.0/oauth/access_token', [
                'grant_type' => 'fb_exchange_token', 'client_id' => $appId, 'client_secret' => $appSecret,
                'fb_exchange_token' => $shortLived['access_token'],
            ])->throw()->json();
        } catch (\Illuminate\Http\Client\RequestException $e) {
            Log::error('MCP Meta Ads: échec OAuth callback', ['body' => $e->response?->body()]);
            abort(500, "Connexion Meta Ads impossible pour le moment.");
        }

        $this->vault->store($site, $slug, [
            'access_token' => $longLived['access_token'],
            'expires_at' => now()->addSeconds($longLived['expires_in'] ?? 5184000)->timestamp,
        ]);

        if ($this->registry->has($slug)) {
            $connector = $this->registry->get($slug);
            $this->permissions->seedDefaultsIfMissing($site, $connector->listTools());
        }
    }

    public function getSettings(Request $request, Site $site, string $slug)
    {
        $record = $site->mcpSiteConnectors()
            ->whereHas('mcpConnector', fn ($q) => $q->where('slug', $slug))
            ->first();

        return response()->json(['settings' => $record->settings ?? []]);
    }

    /**
     * 🆕 Permet à chaque site de configurer SES propres horaires de travail
     * et/ou de surcharger le fuseau détecté automatiquement. Fusionné dans
     * les settings existants (store_url, calendar_id...), rien d'écrasé.
     */
    public function updateSettings(Request $request, Site $site, string $slug)
    {
        $validated = $request->validate([
            'timezone' => ['nullable', 'timezone'],
            'working_hours' => ['nullable', 'array'],

            // google_analytics
            'property_id' => ['nullable', 'string', 'regex:/^[0-9]+$/'],

            // google_search_console
            'site_url' => ['nullable', 'string', 'max:255'],

            // google_ads
            'customer_id' => ['nullable', 'string', 'regex:/^[0-9\-]+$/'],
            'login_customer_id' => ['nullable', 'string', 'regex:/^[0-9\-]+$/'],

            // meta_ads
            'ad_account_id' => ['nullable', 'string', 'regex:/^(act_)?[0-9]+$/'],

            // semrush
            'domain' => ['nullable', 'string', 'max:255'],
            'database' => ['nullable', 'string', 'max:5'],
        ]);

        $record = $site->mcpSiteConnectors()
            ->whereHas('mcpConnector', fn ($q) => $q->where('slug', $slug))
            ->firstOrFail();

        $record->update([
            'settings' => array_merge($record->settings ?? [], array_filter($validated, fn ($v) => $v !== null)),
        ]);

        return response()->json(['status' => 'updated']);
    }
}
