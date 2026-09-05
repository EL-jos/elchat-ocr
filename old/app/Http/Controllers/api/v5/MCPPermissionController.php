<?php

namespace App\Http\Controllers\api\v5;

use App\Domain\MCP\Registry\ConnectorRegistry;
use App\Domain\MCP\Contracts\ProvidesSiteScopedTools;
use App\Domain\MCP\Security\CredentialVault;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesSiteAccess;
use App\Models\Mcp\McpPermission;
use App\Models\Site;
use Illuminate\Http\Request;

/**
 * Gestion des règles de permission (auto / confirm / deny) par site,
 * connecteur et outil. Consommé par le composant Angular permission-editor.
 */
class MCPPermissionController extends Controller
{
    use AuthorizesSiteAccess;
    public function __construct(private readonly ConnectorRegistry $registry, private readonly CredentialVault $vault)
    {
    }

    /**
     * Retourne, pour chaque outil de chaque connecteur ACTIVÉ par le site,
     * l'état de sa règle de permission (ou 'deny' par défaut si absente).
     */
    public function index(Request $request, Site $site)
    {
        $this->authorizeSiteAccess($request, $site);
        $activeSlugs = $site->mcpSiteConnectors()
            ->where('status', 'connected')
            ->with('mcpConnector')
            ->get()
            ->pluck('mcpConnector.slug');

        $existingRules = McpPermission::where('site_id', $site->id)
            ->get()
            ->keyBy(fn ($p) => "{$p->connector_slug}.{$p->tool_name}");

        $result = [];
        foreach ($activeSlugs as $slug) {
            if (!$this->registry->has($slug)) {
                continue;
            }
            $connector = $this->registry->get($slug);
            $tools = $connector instanceof ProvidesSiteScopedTools
                ? $connector->toolsAvailableFor($this->vault->retrieve($site, $slug) ?? [])
                : $connector->listTools();
            foreach ($tools as $tool) {
                $key = "{$slug}.{$tool->name}";
                $existing = $existingRules->get($key);

                $result[] = [
                    'connector' => $slug,
                    'tool' => $tool->name,
                    'description' => $tool->description,
                    'is_write_action' => $tool->isWriteAction,
                    'mode' => $existing->mode ?? 'deny',
                    'actor_scope' => $existing->actor_scope ?? $tool->defaultActorScope,   // 🆕
                    'confirm_actor' => $existing->confirm_actor ?? $tool->defaultConfirmActor, // 🆕
                    'daily_call_limit' => $existing->daily_call_limit ?? null,
                ];
            }
        }

        return response()->json(['data' => $result]);
    }

    public function update(Request $request, Site $site)
    {
        $this->authorizeSiteAccess($request, $site);
        $validated = $request->validate([
            'connector' => ['required', 'string'],
            'tool' => ['required', 'string'],
            'mode' => ['required', 'in:auto,confirm,deny'],
            'actor_scope' => ['required', 'in:visitor,admin'],           // 🆕
            'confirm_actor' => ['nullable', 'in:visitor,admin'],         // 🆕
            'daily_call_limit' => ['nullable', 'integer', 'min:1'],
        ]);

        abort_unless($this->registry->has($validated['connector']), 422, 'Connecteur MCP inconnu.');
        $activeTools = $this->registry->toolsAvailableFor($site);
        $qualified = $validated['connector'] . '__' . $validated['tool'];
        abort_unless(collect($activeTools)->contains(fn ($tool) => ($tool['function']['name'] ?? null) === $qualified), 422, 'Outil MCP indisponible pour ce site ou ses scopes.');

        McpPermission::updateOrCreate(
            [
                'site_id' => $site->id,
                'connector_slug' => $validated['connector'],
                'tool_name' => $validated['tool']
            ],
            [
                'mode' => $validated['mode'],
                'actor_scope' => $validated['actor_scope'],       // 🆕
                'confirm_actor' => $validated['confirm_actor'] ?? null, // 🆕
                'daily_call_limit' => $validated['daily_call_limit'] ?? null
            ],
        );

        return response()->json(['status' => 'updated']);
    }
}
