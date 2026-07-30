<?php

namespace App\Domain\MCP\Agent;

use App\Domain\MCP\Capability\CapabilityResolver;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Registry\ConnectorRegistry;
use App\Models\Mcp\McpConnector;
use App\Models\Site;

/**
 * Traduit les "compétences" choisies pour un agent (capacités abstraites OU
 * outils précis non généralisables) en un catalogue d'édition côté admin,
 * et en une liste concrète d'outils autorisés au moment de l'exécution.
 */
class AgentSkillResolver
{
    public function __construct(
        private readonly ConnectorRegistry $registry,
        private readonly CapabilityResolver $capabilities,
    ) {
    }

    /**
     * Catalogue pour l'éditeur : une entrée par capacité connue (résolue
     * vers son connecteur actif pour ce site), puis une entrée par outil
     * SANS capacité assignée (non généralisable), groupée par connecteur.
     */
    public function catalogFor(Site $site): array
    {
        $capabilityLabels = config('mcp_capabilities', []); // 🆕 chargé une seule fois, en tableau plat
        $activeSlugs = $site->mcpSiteConnectors()->where('status', 'connected')->with('mcpConnector')->get()->pluck('mcpConnector.slug');
        $names = McpConnector::whereIn('slug', $activeSlugs)->get()->keyBy('slug');

        $allTools = [];
        foreach ($activeSlugs as $slug) {
            if (!$this->registry->has($slug)) continue;
            array_push($allTools, ...$this->registry->get($slug)->listTools());
        }

        $seenCapabilities = [];
        $entries = [];

        foreach ($allTools as $tool) {
            /** @var ToolSchema $tool */
            if ($tool->capability) {
                if (isset($seenCapabilities[$tool->capability])) continue;
                $seenCapabilities[$tool->capability] = true;

                $resolvedSlug = $this->capabilities->resolve($site, $tool->capability);
                $entries[] = [
                    'key' => $tool->capability,
                    'type' => 'capability',
                    'label' => $capabilityLabels[$tool->capability] ?? $tool->capability, // 🆕 lookup direct, plus de config() à points
                    'connector' => $resolvedSlug ? ['slug' => $resolvedSlug, 'name' => $names[$resolvedSlug]->name ?? $resolvedSlug, 'icon_url' => $names[$resolvedSlug]->icon_url ?? null] : null,
                ];
                continue;
            }

            $entries[] = [
                'key' => $tool->qualifiedName(),
                'type' => 'tool',
                'label' => $tool->description,
                'connector' => ['slug' => $tool->connectorSlug, 'name' => $names[$tool->connectorSlug]->name ?? $tool->connectorSlug, 'icon_url' => $names[$tool->connectorSlug]->icon_url ?? null],
            ];
        }

        return $entries;
    }

    /**
     * 🆕 Résout les compétences d'un agent en noms d'outils qualifiés
     * concrets pour CE site. Utilisé pour filtrer les outils exposés au LLM.
     *
     * @return string[] noms qualifiés (ex: "google_calendar__create_event")
     */
    public function resolveAllowedToolNames(Site $site, array $skills): array
    {
        $capabilityKeys = config('mcp_capabilities', []); // 🆕

        $resolved = [];
        foreach ($skills as $skill) {
            if (array_key_exists($skill, $capabilityKeys)) { // 🆕 vérifie la clé plate exacte, pas un chemin imbriqué
                $toolName = $this->capabilities->resolveToolName($site, $skill);
                if ($toolName) $resolved[] = $toolName;
                continue;
            }
            $resolved[] = $skill;
        }
        return array_unique($resolved);
    }
}
