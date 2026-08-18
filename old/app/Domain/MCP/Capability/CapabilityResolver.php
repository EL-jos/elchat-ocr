<?php

namespace App\Domain\MCP\Capability;

use App\Domain\MCP\Contracts\ProvidesSiteScopedTools;
use App\Domain\MCP\Registry\ConnectorRegistry;
use App\Domain\MCP\Security\CredentialVault;
use App\Models\Mcp\{McpCapability, McpCapabilityPreference, McpConnector};
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Les capacités sont ENTIÈREMENT définies par l'admin (table mcp_capabilities),
 * plus aucun fichier de config. ToolSchema::$capability (encore présent sur
 * certains outils) ne sert plus qu'à une SUGGESTION de démarrage optionnelle
 * (voir suggestFromToolTags) — jamais lu par la résolution réelle.
 */
class CapabilityResolver
{
    public function __construct(
        private readonly ConnectorRegistry $registry,
        private readonly CredentialVault $vault,
    ) {
    }

    public function definitionsFor(Site $site): Collection
    {
        return McpCapability::where('site_id', $site->id)->get();
    }

    /** @return array<string, string[]> capability_key => [connector_slug,...] réellement disponibles sur ce site */
    public function providersFor(Site $site): array
    {
        $activeToolNames = collect($this->availableToolsCatalog($site))->pluck('tool_name')->all();

        $providers = [];
        foreach ($this->definitionsFor($site) as $capability) {
            $connectors = collect($capability->tool_names)
                ->filter(fn ($t) => in_array($t, $activeToolNames, true))
                ->map(fn ($t) => explode('__', $t)[0])
                ->unique()->values()->all();

            if (!empty($connectors)) $providers[$capability->key] = $connectors;
        }

        return $providers;
    }

    public function resolve(Site $site, string $capabilityKey): ?string
    {
        $preference = McpCapabilityPreference::where('site_id', $site->id)->where('capability', $capabilityKey)->first();
        if ($preference) return $preference->connector_slug;

        $providers = $this->providersFor($site)[$capabilityKey] ?? [];
        return $providers[0] ?? null;
    }

    public function resolveToolName(Site $site, string $capabilityKey): ?string
    {
        $capability = McpCapability::where('site_id', $site->id)->where('key', $capabilityKey)->first();
        if (!$capability) return null;

        $connectorSlug = $this->resolve($site, $capabilityKey);
        if (!$connectorSlug) return null;

        $activeToolNames = collect($this->availableToolsCatalog($site))->pluck('tool_name')->all();

        return collect($capability->tool_names)
            ->first(fn ($t) => str_starts_with($t, "{$connectorSlug}__") && in_array($t, $activeToolNames, true));
    }

    /**
     * Tous les outils réellement disponibles sur ce site (connecteurs
     * connectés, modules Odoo installés inclus) — alimente le sélecteur de
     * l'éditeur de capacités et le catalogue de compétences des agents.
     */
    public function availableToolsCatalog(Site $site): array
    {
        $activeSlugs = $site->mcpSiteConnectors()->where('status', 'connected')->with('mcpConnector')->get()->pluck('mcpConnector.slug');
        $names = McpConnector::whereIn('slug', $activeSlugs)->get()->keyBy('slug');

        $tools = [];
        foreach ($activeSlugs as $slug) {
            if (!$this->registry->has($slug)) continue;
            $connector = $this->registry->get($slug);

            if ($connector instanceof ProvidesSiteScopedTools) {
                $credentials = $this->vault->retrieve($site, $slug);
                $schemas = $credentials ? $connector->toolsAvailableFor($credentials) : [];
            } else {
                $schemas = $connector->listTools();
            }

            foreach ($schemas as $tool) {
                $tools[] = [
                    'tool_name' => $tool->qualifiedName(),
                    'label' => $tool->description,
                    'connector' => ['slug' => $slug, 'name' => $names[$slug]->name ?? $slug, 'icon_url' => $names[$slug]->icon_url ?? null],
                ];
            }
        }

        return $tools;
    }

    /**
     * 🆕 Point de départ optionnel : regroupe les outils actifs par
     * étiquette encore présente dans le code (ToolSchema::$capability),
     * pour pré-remplir des capacités que l'admin peut ensuite librement
     * renommer ou modifier. Jamais utilisé par la résolution runtime.
     */
    public function suggestFromToolTags(Site $site): array
    {
        $activeSlugs = $site->mcpSiteConnectors()->where('status', 'connected')->with('mcpConnector')->get()->pluck('mcpConnector.slug');
        $grouped = [];

        foreach ($activeSlugs as $slug) {
            if (!$this->registry->has($slug)) continue;
            foreach ($this->registry->get($slug)->listTools() as $tool) {
                if (!$tool->capability) continue;
                $grouped[$tool->capability][] = $tool->qualifiedName();
            }
        }

        return collect($grouped)->map(fn ($toolNames, $key) => [
            'key' => $key,
            'label' => ucfirst(str_replace(['_', '.'], [' ', ' — '], $key)),
            'tool_names' => array_unique($toolNames),
        ])->values()->all();
    }
}
