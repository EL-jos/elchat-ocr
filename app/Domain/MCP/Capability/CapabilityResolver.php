<?php

namespace App\Domain\MCP\Capability;

use App\Domain\MCP\Registry\ConnectorRegistry;
use App\Models\Mcp\McpCapabilityPreference;
use App\Models\Site;

class CapabilityResolver
{
    public function __construct(private readonly ConnectorRegistry $registry)
    {
    }

    /**
     * @return array<string, string[]> capability => [connector_slug, ...]
     */
    public function providersFor(Site $site): array
    {
        $activeSlugs = $site->mcpSiteConnectors()->where('status', 'connected')->with('mcpConnector')->get()->pluck('mcpConnector.slug');

        $providers = [];
        foreach ($activeSlugs as $slug) {
            if (!$this->registry->has($slug)) continue;
            foreach ($this->registry->get($slug)->listTools() as $tool) {
                if (!$tool->capability) continue;
                $providers[$tool->capability][] = $slug;
            }
        }

        return array_map('array_unique', $providers);
    }

    public function resolve(Site $site, string $capability): ?string
    {
        $preference = McpCapabilityPreference::where('site_id', $site->id)->where('capability', $capability)->first();
        if ($preference) return $preference->connector_slug;

        $providers = $this->providersFor($site)[$capability] ?? [];
        return $providers[0] ?? null;
    }

    public function resolveToolName(Site $site, string $capability): ?string
    {
        $connectorSlug = $this->resolve($site, $capability);
        if (!$connectorSlug || !$this->registry->has($connectorSlug)) return null;

        $tool = collect($this->registry->get($connectorSlug)->listTools())->first(fn ($t) => $t->capability === $capability);
        return $tool?->qualifiedName();
    }
}
