<?php

namespace App\Http\Controllers\api\v5;

use App\Domain\MCP\Contracts\ProvidesSiteScopedTools;
use App\Domain\MCP\Registry\ConnectorRegistry;
use App\Domain\MCP\Security\CredentialVault;
use App\Domain\MCP\Security\PermissionEngine;
use App\Domain\Microsoft365\Microsoft365OAuthService;
use App\Domain\Microsoft365\Microsoft365ScopeCatalog;
use App\Http\Controllers\Concerns\AuthorizesSiteAccess;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\Mcp\McpConnector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * API du "marketplace de connecteurs" côté admin site (consommée par le
 * module Angular mcp/connector-marketplace).
 */
class MCPConnectorController extends Controller
{
    use AuthorizesSiteAccess;

    private const POST_MESSAGE_OAUTH_CONNECTORS = [
        'google_calendar',
        'google_drive',
        'google_analytics',
        'google_search_console',
        'google_ads',
        'microsoft_365',
        'jira',
        'monday',
    ];

    public function __construct(
        private readonly CredentialVault $vault,
        private readonly PermissionEngine $permissions, // 🆕
        private readonly ConnectorRegistry $registry,   // 🆕
        private readonly Microsoft365OAuthService $microsoftOAuth,
    )
    {
    }

    /**
     * Liste tous les connecteurs disponibles + leur statut d'activation
     * pour le site courant.
     */
    public function index(Request $request, Site $site)
    {
        $this->authorizeSiteAccess($request, $site);

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
                    'provider_tenant_id' => $activation->provider_tenant_id ?? null,
                    'provider_principal_id' => $activation->provider_principal_id ?? null,
                    'provider_principal_upn' => $activation->provider_principal_upn ?? null,
                    'granted_scopes' => $activation->granted_scopes ?? [],
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
        $this->authorizeSiteAccess($request, $site);

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
        $this->authorizeSiteAccess($request, $site);

        $this->vault->revoke($site, $slug);

        return response()->json(['status' => 'revoked']);
    }

    /**
     * Démarre le flux OAuth2 pour un connecteur donné.
     * Retourne l'URL d'autorisation à ouvrir côté Angular.
     */
    public function oauthRedirect(Request $request, Site $site, string $slug)
    {
        $this->authorizeSiteAccess($request, $site);

        if ($slug === 'microsoft_365') {
            // Les permissions ne sont plus choisies dans le dashboard. Elles
            // sont déclarées une fois dans l'inscription Microsoft Entra de
            // l'application ELChat et demandées via Graph /.default.
            $scopes = Microsoft365ScopeCatalog::applicationScopes();
            $forceConsent = $request->boolean('force_consent');

            $state = Str::random(64);
            Cache::put('mcp:oauth:microsoft365:' . hash('sha256', $state), [
                'site_id' => (string) $site->id,
                'scopes' => $scopes,
            ], now()->addMinutes(10));

            return response()->json([
                'authorization_url' => $this->microsoftOAuth->authorizeUrl($state, $scopes, $forceConsent),
                'scopes' => $scopes,
                'authorization_source' => 'microsoft_entra_app_registration',
            ]);
        }

        $redirectUri = $this->oauthRedirectUri($slug);
        $statePayload = [
            'site_id' => $site->id,
            'connector' => $slug,
            // Les fournisseurs OAuth compatibles reviennent dans la fenêtre
            // popup et notifient le dashboard sans remplacer la page courante.
            'response_mode' => in_array($slug, self::POST_MESSAGE_OAUTH_CONNECTORS, true)
                ? 'post_message'
                : 'redirect',
        ];

        if ($slug === 'monday' && config('mcp.connectors.monday.use_pkce', false)) {
            $statePayload['code_verifier'] = $this->pkceVerifier();
        }

        $state = encrypt($statePayload);

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

        if ($slug === 'jira') {
            $clientId = config('mcp.connectors.jira.client_id');
            if (!$clientId) {
                Log::error('MCP: JIRA_CLIENT_ID absent ou config non rechargée.');
                return response()->json(['message' => 'Connecteur Jira mal configuré côté serveur.'], 500);
            }

            $url = 'https://auth.atlassian.com/authorize?' . http_build_query([
                'audience' => 'api.atlassian.com',
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'response_type' => 'code',
                'prompt' => 'consent',
                'scope' => implode(' ', [
                    'read:jira-work', 'write:jira-work', 'read:jira-user', 'offline_access',
                ]),
                'state' => $state,
            ]);

            return response()->json([
                'authorization_url' => $url,
                'scopes' => ['read:jira-work', 'write:jira-work', 'read:jira-user', 'offline_access'],
            ]);
        }

        if ($slug === 'monday') {
            $clientId = config('mcp.connectors.monday.client_id');
            if (!$clientId) {
                Log::error('MCP: MONDAY_CLIENT_ID absent ou config non rechargée.');
                return response()->json(['message' => 'Connecteur monday.com mal configuré côté serveur.'], 500);
            }

            $mondayParams = [
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'response_type' => 'code',
                'scope' => implode(' ', [
                    'account:read', 'boards:read', 'boards:write', 'me:read',
                    'updates:read', 'updates:write', 'users:read', 'workspaces:read',
                ]),
                'state' => $state,
            ];

            if (config('mcp.connectors.monday.use_pkce', false)) {
                $mondayParams['code_challenge'] = $this->pkceChallenge($statePayload['code_verifier']);
                $mondayParams['code_challenge_method'] = 'S256';
            }

            return response()->json([
                'authorization_url' => 'https://auth.monday.com/oauth2/authorize?' . http_build_query($mondayParams),
                'scopes' => preg_split('/\s+/', $mondayParams['scope']),
            ]);
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
        if ($slug === 'microsoft_365') {
            return $this->microsoftOAuthCallback($request);
        }

        $state = decrypt($request->query('state'));

        if (($state['connector'] ?? null) !== $slug) {
            abort(400, 'Le connecteur OAuth ne correspond pas à la demande initiale.');
        }

        $site = Site::findOrFail($state['site_id']);
        $usePostMessage = ($state['response_mode'] ?? null) === 'post_message'
            && in_array($slug, self::POST_MESSAGE_OAUTH_CONNECTORS, true);
        $code = $request->query('code');
        $redirectUri = $this->oauthRedirectUri($slug);

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

        if ($slug === 'jira') {
            return $this->handleJiraOAuthCallback($site, $code, $redirectUri, $usePostMessage);
        }

        if ($slug === 'monday') {
            return $this->handleMondayOAuthCallback($site, $code, $redirectUri, $state, $usePostMessage);
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
            in_array($slug, self::POST_MESSAGE_OAUTH_CONNECTORS, true) => 'https://oauth2.googleapis.com/token',
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
     * Termine un OAuth dans la popup. Si le callback a été ouvert
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

    private function microsoftOAuthCallback(Request $request)
    {
        $state = (string) $request->query('state', '');
        $payload = $state !== ''
            ? Cache::pull('mcp:oauth:microsoft365:' . hash('sha256', $state))
            : null;

        if (!is_array($payload) || empty($payload['site_id'])) {
            abort(400, 'La demande de connexion Microsoft 365 est expirée ou invalide.');
        }

        $site = Site::findOrFail($payload['site_id']);
        $usePostMessage = true;

        if ($request->filled('error')) {
            $message = $request->query('error') === 'access_denied'
                ? 'Autorisation Microsoft 365 annulée. Aucune connexion n’a été enregistrée.'
                : 'Microsoft 365 n’a pas pu autoriser cette connexion.';
            return $this->oauthResult($site, 'microsoft_365', false, $message, $usePostMessage);
        }

        $code = $request->query('code');
        if (!is_string($code) || $code === '') {
            return $this->oauthResult($site, 'microsoft_365', false, 'Le code Microsoft 365 est absent. Veuillez recommencer.', $usePostMessage);
        }

        try {
            $tokenResponse = $this->microsoftOAuth->exchangeCode($code);
            $credentials = $this->microsoftOAuth->normalizeToken($tokenResponse);
            if (empty($credentials['granted_scopes'])) {
                // Microsoft may omit `scope` in a token response. The state
                // payload is the server-side allowlisted request, never raw
                // frontend input, so it is a safe fallback for tool scoping.
                $credentials['granted_scopes'] = $payload['scopes'] ?? [];
            }
            $profile = $this->microsoftOAuth->profile($credentials['access_token']);
        } catch (\Throwable $exception) {
            Log::warning('MCP Microsoft 365: échec OAuth callback', ['type' => get_class($exception)]);
            return $this->oauthResult($site, 'microsoft_365', false, 'La connexion Microsoft 365 n’a pas pu être finalisée.', $usePostMessage);
        }

        $existing = $site->mcpSiteConnectors()
            ->whereHas('mcpConnector', fn ($q) => $q->where('slug', 'microsoft_365'))
            ->first();
        $tenantId = $this->microsoftOAuth->tenantIdFromToken($tokenResponse);
        if ($existing?->provider_tenant_id && $tenantId && $existing->provider_tenant_id !== $tenantId) {
            return $this->oauthResult($site, 'microsoft_365', false, 'Ce site est déjà relié à un autre tenant Microsoft. Désactivez d’abord la connexion existante.', $usePostMessage);
        }

        $metadata = [
            'provider_tenant_id' => $tenantId,
            'provider_principal_id' => $profile['id'] ?? null,
            'provider_principal_upn' => $profile['userPrincipalName'] ?? ($profile['mail'] ?? null),
            'granted_scopes' => $credentials['granted_scopes'] ?? ($payload['scopes'] ?? []),
        ];

        $this->vault->store($site, 'microsoft_365', $credentials, [], $metadata);
        if ($this->registry->has('microsoft_365')) {
            $connector = $this->registry->get('microsoft_365');
            $tools = method_exists($connector, 'toolsAvailableFor')
                ? $connector->toolsAvailableFor($this->vault->retrieve($site, 'microsoft_365') ?? [])
                : $connector->listTools();
            $this->permissions->seedDefaultsIfMissing($site, $tools);
        }

        return $this->oauthResult($site, 'microsoft_365', true, 'Microsoft 365 est maintenant connecté à ELChat.', $usePostMessage);
    }

    private function handleJiraOAuthCallback(Site $site, string $code, string $redirectUri, bool $usePostMessage)
    {
        try {
            $tokenResponse = \Illuminate\Support\Facades\Http::asJson()->post('https://auth.atlassian.com/oauth/token', [
                'grant_type' => 'authorization_code',
                'client_id' => config('mcp.connectors.jira.client_id'),
                'client_secret' => config('mcp.connectors.jira.client_secret'),
                'code' => $code,
                'redirect_uri' => $redirectUri,
            ])->throw()->json();

            if (empty($tokenResponse['access_token'])) {
                throw new \RuntimeException('access_token absent');
            }

            $resources = \Illuminate\Support\Facades\Http::withToken($tokenResponse['access_token'])
                ->acceptJson()->get('https://api.atlassian.com/oauth/token/accessible-resources')
                ->throw()->json();
        } catch (\Throwable $exception) {
            Log::warning('MCP Jira: échec OAuth callback', ['type' => get_class($exception)]);
            return $this->oauthResult($site, 'jira', false, 'La connexion Jira n’a pas pu être finalisée. Vérifiez la configuration OAuth Jira puis réessayez.', $usePostMessage);
        }

        $resource = collect(is_array($resources) ? $resources : [])->first(function ($candidate) {
            $scopes = $this->oauthScopes($candidate['scopes'] ?? []);
            return in_array('read:jira-work', $scopes, true)
                || in_array('write:jira-work', $scopes, true)
                || (empty($scopes) && str_contains((string) ($candidate['url'] ?? ''), '.atlassian.net'));
        });

        if (!$resource || empty($resource['id'])) {
            return $this->oauthResult($site, 'jira', false, 'Aucun site Jira Cloud accessible n’a été sélectionné pour cette connexion.', $usePostMessage);
        }

        $credentials = [
            'access_token' => $tokenResponse['access_token'],
            'cloud_id' => $resource['id'],
            'site_url' => $resource['url'] ?? null,
            'expires_at' => now()->addSeconds((int) ($tokenResponse['expires_in'] ?? 3600))->timestamp,
            'granted_scopes' => $this->oauthScopes($tokenResponse['scope'] ?? ($resource['scopes'] ?? [])),
        ];
        if (!empty($tokenResponse['refresh_token'])) $credentials['refresh_token'] = $tokenResponse['refresh_token'];

        $this->vault->store($site, 'jira', $credentials, [], [
            'provider_tenant_id' => $resource['id'],
            'granted_scopes' => $credentials['granted_scopes'],
        ]);
        if ($this->registry->has('jira')) {
            $this->permissions->seedDefaultsIfMissing($site, $this->registry->get('jira')->listTools());
        }

        return $this->oauthResult($site, 'jira', true, 'Jira est maintenant connecté à ELChat.', $usePostMessage);
    }

    private function handleMondayOAuthCallback(Site $site, string $code, string $redirectUri, array $state, bool $usePostMessage)
    {
        $payload = [
            'grant_type' => 'authorization_code',
            'client_id' => config('mcp.connectors.monday.client_id'),
            'client_secret' => config('mcp.connectors.monday.client_secret'),
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ];
        $usePkce = config('mcp.connectors.monday.use_pkce', false);
        if ($usePkce && !empty($state['code_verifier'])) $payload['code_verifier'] = $state['code_verifier'];

        try {
            $request = $usePkce
                ? \Illuminate\Support\Facades\Http::asJson()
                : \Illuminate\Support\Facades\Http::asForm();
            $tokenResponse = $request->post(config('mcp.connectors.monday.token_endpoint'), $payload)->throw()->json();
        } catch (\Throwable $exception) {
            Log::warning('MCP monday: échec OAuth callback', ['type' => get_class($exception)]);
            return $this->oauthResult($site, 'monday', false, 'La connexion monday.com n’a pas pu être finalisée. Vérifiez la configuration OAuth monday puis réessayez.', $usePostMessage);
        }

        if (empty($tokenResponse['access_token'])) {
            return $this->oauthResult($site, 'monday', false, 'monday.com n’a pas retourné de jeton d’accès valide. Veuillez réessayer.', $usePostMessage);
        }

        $credentials = [
            'access_token' => $tokenResponse['access_token'],
            'granted_scopes' => $this->oauthScopes($tokenResponse['scope'] ?? []),
        ];
        if (!empty($tokenResponse['refresh_token'])) $credentials['refresh_token'] = $tokenResponse['refresh_token'];
        $expiresAt = $tokenResponse['expires_in'] ?? $this->jwtExpiration($tokenResponse['access_token']);
        if ($expiresAt) {
            $credentials['expires_at'] = isset($tokenResponse['expires_in'])
                ? now()->addSeconds((int) $expiresAt)->timestamp
                : (int) $expiresAt;
        }

        $this->vault->store($site, 'monday', $credentials, [], ['granted_scopes' => $credentials['granted_scopes']]);
        if ($this->registry->has('monday')) {
            $this->permissions->seedDefaultsIfMissing($site, $this->registry->get('monday')->listTools());
        }

        return $this->oauthResult($site, 'monday', true, 'monday.com est maintenant connecté à ELChat.', $usePostMessage);
    }

    private function connectorUrl(Site $site, string $slug): string
    {
        $dashboardUrl = rtrim((string) config('app.frontend_dashboard_url', 'https://elchat.io'), '/');

        return "{$dashboardUrl}/app/site/{$site->id}/settings/connectors?connected=" . urlencode($slug);
    }

    private function pkceVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
    }

    private function pkceChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function oauthScopes(mixed $scopes): array
    {
        return collect(is_array($scopes) ? $scopes : (preg_split('/[\s,]+/', trim((string) $scopes)) ?: []))
            ->filter()->values()->all();
    }

    private function jwtExpiration(string $token): ?int
    {
        $parts = explode('.', $token);
        if (count($parts) < 2) return null;

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')) ?: '', true);
        return is_array($payload) && isset($payload['exp']) ? (int) $payload['exp'] : null;
    }

    /**
     * Retourne l'URI déclarée chez le fournisseur OAuth quand elle est
     * configurée. Le fallback conserve le fonctionnement local des autres
     * connecteurs, qui utilisent l'URL générée depuis APP_URL.
     */
    private function oauthRedirectUri(string $slug): string
    {
        $configured = match ($slug) {
            'jira' => config('mcp.connectors.jira.redirect_uri'),
            'monday' => config('mcp.connectors.monday.redirect_uri'),
            default => null,
        };

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        return route('mcp.oauth.callback', ['slug' => $slug]);
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
        $this->authorizeSiteAccess($request, $site);

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
        $this->authorizeSiteAccess($request, $site);

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

            // jira
            'default_project_key' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/'],

            // monday
            'default_board_id' => ['nullable', 'string', 'max:64', 'regex:/^[0-9]+$/'],

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
