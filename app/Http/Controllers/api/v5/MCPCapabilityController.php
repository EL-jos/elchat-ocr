<?php

namespace App\Http\Controllers\api\v5;

use App\Domain\MCP\Capability\CapabilityResolver;
use App\Http\Controllers\Controller;
use App\Models\Mcp\McpCapabilityPreference;
use App\Models\Site;
use Illuminate\Http\Request;

class MCPCapabilityController extends Controller
{
    public function __construct(private readonly CapabilityResolver $resolver)
    {
    }

    /** Ne remonte que les capacités où 2+ connecteurs actifs se concurrencent — le seul cas où un réglage a du sens. */
    public function index(Request $request, Site $site)
    {
        $providers = $this->resolver->providersFor($site);
        $preferences = McpCapabilityPreference::where('site_id', $site->id)->pluck('connector_slug', 'capability');

        $result = collect($providers)
            ->filter(fn ($connectors) => count($connectors) > 1)
            ->map(fn ($connectors, $capability) => [
                'capability' => $capability,
                'label' => config("mcp_capabilities.{$capability}", $capability),
                'connectors' => array_values($connectors),
                'preferred' => $preferences[$capability] ?? array_values($connectors)[0],
            ])
            ->values();

        return response()->json(['data' => $result]);
    }

    public function update(Request $request, Site $site)
    {
        $validated = $request->validate([
            'capability' => ['required', 'string'],
            'connector_slug' => ['required', 'string'],
        ]);

        McpCapabilityPreference::updateOrCreate(
            ['site_id' => $site->id, 'capability' => $validated['capability']],
            ['connector_slug' => $validated['connector_slug']]
        );

        return response()->json(['status' => 'updated']);
    }
}
