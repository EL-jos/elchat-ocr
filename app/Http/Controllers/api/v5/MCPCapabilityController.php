<?php

namespace App\Http\Controllers\api\v5;

use App\Domain\MCP\Capability\CapabilityActionPlaybookEngine;
use App\Domain\MCP\Capability\CapabilityPlaybookEngine;
use App\Domain\MCP\Capability\CapabilityResolver;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesSiteAccess;
use App\Models\Mcp\{McpCapability, McpCapabilityPreference, McpConnector};
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MCPCapabilityController extends Controller
{
    use AuthorizesSiteAccess;
    public function __construct(
        private readonly CapabilityResolver $resolver,
        private readonly CapabilityPlaybookEngine $playbookEngine, // 🆕
        private readonly CapabilityActionPlaybookEngine $actionPlaybookEngine, // 🆕
    )
    { }

    /** Outils actifs disponibles sur ce site — alimente le sélecteur de la création/édition d'une capacité. */
    public function toolsCatalog(Request $request, Site $site)
    {
        $this->authorizeSiteAccess($request, $site);
        return response()->json([
            'data' => $this->resolver->configurationToolsCatalog($site),
            'modules' => $this->resolver->configurationModulesCatalog($site),
        ]);
    }

    public function definitions(Request $request, Site $site)
    {
        $this->authorizeSiteAccess($request, $site);
        return response()->json(['data' => McpCapability::where('site_id', $site->id)->orderBy('label')->get()]);
    }

    public function store(Request $request, Site $site)
    {
        $this->authorizeSiteAccess($request, $site);
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
        $this->authorizeSiteAccess($request, $site);
        abort_unless($capability->site_id === $site->id, 404);
        $capability->update($this->validatedDefinition($request));
        return response()->json(['data' => $capability]);
    }

    public function destroy(Request $request, Site $site, McpCapability $capability)
    {
        $this->authorizeSiteAccess($request, $site);
        abort_unless($capability->site_id === $site->id, 404);
        $capability->delete();
        return response()->json(['status' => 'deleted']);
    }

    /** 🆕 Bootstrap pratique, jamais obligatoire — voir CapabilityResolver::suggestFromToolTags. */
    public function suggest(Request $request, Site $site)
    {
        $this->authorizeSiteAccess($request, $site);
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

    /**
     * 🆕 Connecteurs PAS ENCORE activés recommandés pour ce site, à partir
     * du référentiel éditorial (mcp_capability_playbooks) — voir
     * CapabilityPlaybookEngine. Distinct de suggest() ci-dessus, qui lui ne
     * regroupe que des outils déjà actifs. Alimente le bandeau "Recommandé
     * pour vous" du marketplace de connecteurs.
     */
    public function recommended(Request $request, Site $site)
    {
        $this->authorizeSiteAccess($request, $site);
        return response()->json(['data' => $this->playbookEngine->suggestFor($site)]);
    }

    /** 🆕 L'admin ignore une suggestion — ne la revoit plus pour ce site. */
    public function dismissRecommendation(Request $request, Site $site, string $key)
    {
        $this->authorizeSiteAccess($request, $site);
        $this->playbookEngine->dismiss($site, $key);
        return response()->json(['status' => 'dismissed']);
    }

    /** 🆕 Combos d'actions (même connecteur ou cross-connecteur) — bandeau de CapabilityManagerComponent. */
    public function recommendedActions(Request $request, Site $site)
    {
        $this->authorizeSiteAccess($request, $site);
        return response()->json(['data' => $this->actionPlaybookEngine->suggestFor($site)]);
    }

    /** 🆕 Accepte : crée directement la McpCapability correspondante. */
    public function acceptActionRecommendation(Request $request, Site $site, string $key)
    {
        $this->authorizeSiteAccess($request, $site);
        $capability = $this->actionPlaybookEngine->accept($site, $key);
        return response()->json(['data' => $capability]);
    }

    public function dismissActionRecommendation(Request $request, Site $site, string $key)
    {
        $this->authorizeSiteAccess($request, $site);
        $this->actionPlaybookEngine->dismiss($site, $key);
        return response()->json(['status' => 'dismissed']);
    }

    /** Conflits actuels (2+ connecteurs actifs pour une même capacité). */
    public function index(Request $request, Site $site)
    {
        $this->authorizeSiteAccess($request, $site);
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
        $this->authorizeSiteAccess($request, $site);
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
        $this->authorizeSiteAccess($request, $site);
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
