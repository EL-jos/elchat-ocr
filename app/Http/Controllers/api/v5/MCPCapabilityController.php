<?php

namespace App\Http\Controllers\api\v5;

use App\Domain\MCP\Capability\CapabilityResolver;
use App\Http\Controllers\Controller;
use App\Models\Mcp\{McpCapability, McpCapabilityPreference, McpConnector};
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MCPCapabilityController extends Controller
{
    public function __construct(private readonly CapabilityResolver $resolver)
    {
    }

    /** Outils actifs disponibles sur ce site — alimente le sélecteur de la création/édition d'une capacité. */
    public function toolsCatalog(Request $request, Site $site)
    {
        return response()->json(['data' => $this->resolver->availableToolsCatalog($site)]);
    }

    public function definitions(Request $request, Site $site)
    {
        return response()->json(['data' => McpCapability::where('site_id', $site->id)->orderBy('label')->get()]);
    }

    public function store(Request $request, Site $site)
    {
        $validated = $this->validatedDefinition($request);
        $capability = McpCapability::create([
            'id' => (string) Str::uuid(), 'site_id' => $site->id,
            'key' => Str::slug($validated['label']) . '-' . Str::random(6),
            ...$validated,
        ]);
        return response()->json(['data' => $capability]);
    }

    public function update(Request $request, Site $site, McpCapability $capability)
    {
        abort_unless($capability->site_id === $site->id, 404);
        $capability->update($this->validatedDefinition($request));
        return response()->json(['data' => $capability]);
    }

    public function destroy(Request $request, Site $site, McpCapability $capability)
    {
        abort_unless($capability->site_id === $site->id, 404);
        $capability->delete();
        return response()->json(['status' => 'deleted']);
    }

    /** 🆕 Bootstrap pratique, jamais obligatoire — voir CapabilityResolver::suggestFromToolTags. */
    public function suggest(Request $request, Site $site)
    {
        $created = [];
        foreach ($this->resolver->suggestFromToolTags($site) as $suggestion) {
            if (McpCapability::where('site_id', $site->id)->where('key', $suggestion['key'])->exists()) continue;
            $created[] = McpCapability::create([
                'id' => (string) Str::uuid(), 'site_id' => $site->id,
                'key' => $suggestion['key'], 'label' => $suggestion['label'], 'tool_names' => $suggestion['tool_names'],
            ]);
        }
        return response()->json(['data' => $created]);
    }

    /** Conflits actuels (2+ connecteurs actifs pour une même capacité). */
    public function index(Request $request, Site $site)
    {
        $providers = $this->resolver->providersFor($site);
        $preferences = McpCapabilityPreference::where('site_id', $site->id)->pluck('connector_slug', 'capability');
        $labels = McpCapability::where('site_id', $site->id)->pluck('label', 'key');
        $names = McpConnector::whereIn('slug', collect($providers)->flatten()->unique())->get()->keyBy('slug');

        $result = collect($providers)
            ->filter(fn ($connectors) => count($connectors) > 1)
            ->map(fn ($connectors, $capability) => [
                'capability' => $capability,
                'label' => $labels[$capability] ?? $capability,
                'connectors' => collect($connectors)->map(fn ($slug) => ['slug' => $slug, 'name' => $names[$slug]->name ?? $slug, 'icon_url' => $names[$slug]->icon_url ?? null])->values(),
                'preferred' => $preferences[$capability] ?? array_values($connectors)[0],
            ])->values();

        return response()->json(['data' => $result]);
    }

    public function updatePreference(Request $request, Site $site)
    {
        $validated = $request->validate(['capability' => ['required', 'string'], 'connector_slug' => ['required', 'string']]);
        McpCapabilityPreference::updateOrCreate(
            ['site_id' => $site->id, 'capability' => $validated['capability']],
            ['connector_slug' => $validated['connector_slug']]
        );
        return response()->json(['status' => 'updated']);
    }

    /** Catalogue complet — alimente le sélecteur de l'éditeur de workflow. */
    public function catalog(Request $request, Site $site)
    {
        $definitions = McpCapability::where('site_id', $site->id)->get();
        $providers = $this->resolver->providersFor($site);
        $preferences = McpCapabilityPreference::where('site_id', $site->id)->pluck('connector_slug', 'capability');
        $names = McpConnector::whereIn('slug', collect($providers)->flatten()->unique())->get()->keyBy('slug');

        $result = $definitions->map(function ($capability) use ($providers, $names, $preferences) {
            $available = collect($providers[$capability->key] ?? [])->map(fn ($slug) => ['slug' => $slug, 'name' => $names[$slug]->name ?? $slug, 'icon_url' => $names[$slug]->icon_url ?? null])->values();
            return [
                'capability' => $capability->key, 'label' => $capability->label,
                'available_connectors' => $available,
                'resolved_connector' => $preferences[$capability->key] ?? $available->first()['slug'] ?? null,
            ];
        })->values();

        return response()->json(['data' => $result]);
    }

    private function validatedDefinition(Request $request): array
    {
        return $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'tool_names' => ['required', 'array', 'min:1'],
            'tool_names.*' => ['string'],
        ]);
    }
}
