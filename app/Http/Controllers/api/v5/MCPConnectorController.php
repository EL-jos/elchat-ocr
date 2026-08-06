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
        $clientId = config('mcp.connectors.google_calendar.client_id');
        $redirectUri = route('mcp.oauth.callback', ['slug' => $slug]); // 🆕 plus jamais désynchronisé de la vraie route

        // 🆕 Échoue explicitement plutôt que d'envoyer une requête incomplète à
        // Google (ce qui produisait l'erreur "Missing required parameter" côté
        // visiteur, sans aucune information exploitable dans nos logs).
        if (!$clientId) {
            Log::error('MCP: GOOGLE_CALENDAR_CLIENT_ID absent ou config non rechargée.');
            return response()->json(['message' => "Connecteur Google Calendar mal configuré côté serveur."], 500);
        }

        $state = encrypt(['site_id' => $site->id, 'connector' => $slug]);

        $url = match ($slug) {
            'google_calendar' => 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
                    'client_id' => $clientId,
                    'redirect_uri' => $redirectUri,
                    'response_type' => 'code',
                    'access_type' => 'offline',
                    'prompt' => 'consent',
                    'scope' => 'https://www.googleapis.com/auth/calendar',
                    'state' => $state,
                ]),
            'google_drive' => 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
                    'client_id' => config('mcp.connectors.google_drive.client_id'), 'redirect_uri' => $redirectUri, 'response_type' => 'code',
                    'access_type' => 'offline', 'prompt' => 'consent', 'scope' => 'https://www.googleapis.com/auth/drive', 'state' => $state,
                ]),
            'onedrive' => 'https://login.microsoftonline.com/' . config('mcp.connectors.onedrive.tenant', 'common') . '/oauth2/v2.0/authorize?' . http_build_query([
                    'client_id' => config('mcp.connectors.onedrive.client_id'), 'redirect_uri' => $redirectUri, 'response_type' => 'code',
                    'scope' => 'Files.ReadWrite offline_access', 'state' => $state,
                ]),
            default => abort(404, "OAuth non supporté pour {$slug}"),
        };

        return response()->json(['authorization_url' => $url]);
    }

    public function oauthCallback(Request $request, string $slug)
    {
        $state = decrypt($request->query('state'));
        $site = Site::findOrFail($state['site_id']);
        $code = $request->query('code');

        $tokenEndpoint = match ($slug) {
            'google_calendar', 'google_drive' => 'https://oauth2.googleapis.com/token',
            'onedrive' => 'https://login.microsoftonline.com/' . config('mcp.connectors.onedrive.tenant', 'common') . '/oauth2/v2.0/token',
        };

        $tokenResponse = \Illuminate\Support\Facades\Http::asForm()->post($tokenEndpoint, [
            'client_id' => config("mcp.connectors.{$slug}.client_id"),
            'client_secret' => config("mcp.connectors.{$slug}.client_secret"),
            'redirect_uri' => route('mcp.oauth.callback', ['slug' => $slug]),
            'code' => $code, 'grant_type' => 'authorization_code',
        ])->throw()->json();

        $this->vault->store($site, $slug, [
            'access_token' => $tokenResponse['access_token'],
            'refresh_token' => $tokenResponse['refresh_token'],
            'expires_at' => now()->addSeconds($tokenResponse['expires_in'])->timestamp,
        ]);

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

        return redirect("https://elchat.io/app/site/{$site->id}/settings/connectors?connected={$slug}");
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
            'timezone' => ['nullable', 'timezone'], // validation native Laravel (ex: "Indian/Reunion")
            'working_hours' => ['nullable', 'array'],
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
