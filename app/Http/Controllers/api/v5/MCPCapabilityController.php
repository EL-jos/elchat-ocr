<?php

namespace App\Http\Controllers\api\v5;

use App\Domain\MCP\Capability\CapabilityResolver;
use App\Http\Controllers\Controller;
use App\Models\Mcp\McpCapabilityPreference;
use App\Models\Mcp\McpConnector;
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
        $names = McpConnector::whereIn('slug', collect($providers)->flatten()->unique())->get()->keyBy('slug');

        $result = collect($providers)
            ->filter(fn ($connectors) => count($connectors) > 1)
            ->map(fn ($connectors, $capability) => [
                'capability' => $capability,
                'label' => config('mcp_capabilities', [])[$capability] ?? $capability,
                'connectors' => collect($connectors)->map(fn ($slug) => [
                    'slug' => $slug, 'name' => $names[$slug]->name ?? $slug, 'icon_url' => $names[$slug]->icon_url ?? null,
                ])->values(),
                'preferred' => $preferences[$capability] ?? array_values($connectors)[0],
            ])
            ->values();

        return response()->json(['data' => $result]);
    }

    /**
     * 🆕 Catalogue COMPLET des capacités connues (config/mcp_capabilities.php),
     * avec pour chacune les connecteurs qui la fournissent réellement sur ce
     * site + leur logo. Alimente le sélecteur visuel de l'éditeur de workflow —
     * une capacité sans connecteur disponible reste visible mais grisée.
     */
    public function catalog(Request $request, Site $site)
    {
        $providers = $this->resolver->providersFor($site);
        $preferences = McpCapabilityPreference::where('site_id', $site->id)->pluck('connector_slug', 'capability');
        $names = McpConnector::whereIn('slug', collect($providers)->flatten()->unique())->get()->keyBy('slug');

        $result = collect(config('mcp_capabilities', []))->map(function ($label, $capability) use ($providers, $names, $preferences) {
            $available = collect($providers[$capability] ?? [])->map(fn ($slug) => [
                'slug' => $slug, 'name' => $names[$slug]->name ?? $slug, 'icon_url' => $names[$slug]->icon_url ?? null,
            ])->values();

            return [
                'capability' => $capability, 'label' => $label,
                'available_connectors' => $available,
                'resolved_connector' => $preferences[$capability] ?? $available->first()['slug'] ?? null,
            ];
        })->values();

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
