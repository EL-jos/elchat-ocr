<?php

namespace App\Http\Controllers\api\v5;

use App\Domain\MCP\Registry\ConnectorRegistry;
use App\Http\Controllers\Controller;
use App\Models\Mcp\McpPermission;
use App\Models\Site;
use Illuminate\Http\Request;

/**
 * Gestion des règles de permission (auto / confirm / deny) par site,
 * connecteur et outil. Consommé par le composant Angular permission-editor.
 */
class MCPPermissionController extends Controller
{
    public function __construct(private readonly ConnectorRegistry $registry)
    {
    }

    /**
     * Retourne, pour chaque outil de chaque connecteur ACTIVÉ par le site,
     * l'état de sa règle de permission (ou 'deny' par défaut si absente).
     */
    public function index(Request $request, Site $site)
    {
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
            foreach ($this->registry->get($slug)->listTools() as $tool) {
                $key = "{$slug}.{$tool->name}";
                $existing = $existingRules->get($key);

                $result[] = [
                    'connector' => $slug,
                    'tool' => $tool->name,
                    'description' => $tool->description,
                    'is_write_action' => $tool->isWriteAction,
                    'mode' => $existing->mode ?? 'deny',
                    'daily_call_limit' => $existing->daily_call_limit ?? null,
                ];
            }
        }

        return response()->json(['data' => $result]);
    }

    public function update(Request $request, Site $site)
    {
        $validated = $request->validate([
            'connector' => ['required', 'string'],
            'tool' => ['required', 'string'],
            'mode' => ['required', 'in:auto,confirm,deny'],
            'daily_call_limit' => ['nullable', 'integer', 'min:1'],
        ]);

        McpPermission::updateOrCreate(
            ['site_id' => $site->id, 'connector_slug' => $validated['connector'], 'tool_name' => $validated['tool']],
            ['mode' => $validated['mode'], 'daily_call_limit' => $validated['daily_call_limit'] ?? null],
        );

        return response()->json(['status' => 'updated']);
    }
}
