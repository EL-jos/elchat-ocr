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
        $state = encrypt(['site_id' => $site->id, 'connector' => $slug]);

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

        abort(404, "OAuth non supporté pour {$slug}");
    }

    public function oauthCallback(Request $request, string $slug)
    {
        $state = decrypt($request->query('state'));
        $site = Site::findOrFail($state['site_id']);
        $code = $request->query('code');
        $redirectUri = route('mcp.oauth.callback', ['slug' => $slug]);

        if ($slug === 'meta_ads') {
            $this->handleMetaOAuthCallback($site, $slug, $code, $redirectUri);
            return redirect("https://elchat.io/app/site/{$site->id}/settings/connectors?connected={$slug}");
        }

        $googleFamily = ['google_calendar', 'google_drive', 'google_analytics', 'google_search_console', 'google_ads'];

        $tokenEndpoint = match (true) {
            in_array($slug, $googleFamily) => 'https://oauth2.googleapis.com/token',
            $slug === 'onedrive' => 'https://login.microsoftonline.com/' . config('mcp.connectors.onedrive.tenant', 'common') . '/oauth2/v2.0/token',
            default => abort(404, "OAuth non supporté pour {$slug}"),
        };

        $tokenResponse = \Illuminate\Support\Facades\Http::asForm()->post($tokenEndpoint, [
            'client_id' => config("mcp.connectors.{$slug}.client_id"),
            'client_secret' => config("mcp.connectors.{$slug}.client_secret"),
            'redirect_uri' => $redirectUri,
            'code' => $code, 'grant_type' => 'authorization_code',
        ])->throw()->json();

        $this->vault->store($site, $slug, [
            'access_token' => $tokenResponse['access_token'],
            'refresh_token' => $tokenResponse['refresh_token'],
            'expires_at' => now()->addSeconds($tokenResponse['expires_in'])->timestamp,
        ]);

        // Pré-remplit mcp_permissions avec les valeurs par défaut suggérées par
        // chaque outil. N'écrase jamais une règle déjà configurée.
        if ($this->registry->has($slug)) {
            $connector = $this->registry->get($slug);
            $tools = $connector instanceof ProvidesSiteScopedTools
                ? $connector->toolsAvailableFor($this->vault->retrieve($site, $slug) ?? [])
                : $connector->listTools();
            $this->permissions->seedDefaultsIfMissing($site, $tools);
        }

        return redirect("https://elchat.io/app/site/{$site->id}/settings/connectors?connected={$slug}");
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
