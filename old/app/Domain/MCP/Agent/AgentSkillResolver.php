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
        $allTools = $this->capabilities->availableToolsCatalog($site);
        $definitions = $this->capabilities->definitionsFor($site);
        $groupedToolNames = $definitions->flatMap(fn ($c) => $c->tool_names)->all();

        $entries = [];
        foreach ($definitions as $capability) {
            $resolvedSlug = $this->capabilities->resolve($site, $capability->key);
            $connectorInfo = $resolvedSlug ? collect($allTools)->first(fn ($t) => $t['connector']['slug'] === $resolvedSlug)['connector'] ?? null : null;
            $entries[] = ['key' => $capability->key, 'type' => 'capability', 'label' => $capability->label, 'connector' => $connectorInfo];
        }

        foreach ($allTools as $tool) {
            if (in_array($tool['tool_name'], $groupedToolNames, true)) continue; // déjà couvert par une capacité admin
            $entries[] = ['key' => $tool['tool_name'], 'type' => 'tool', 'label' => $tool['label'], 'connector' => $tool['connector']];
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
        $definitionKeys = $this->capabilities->definitionsFor($site)->pluck('key')->all(); // 🆕 remplace le config()

        $resolved = [];
        foreach ($skills as $skill) {
            if (in_array($skill, $definitionKeys, true)) {
                $toolName = $this->capabilities->resolveToolName($site, $skill);
                if ($toolName) $resolved[] = $toolName;
                continue;
            }
            $resolved[] = $skill;
        }
        return array_unique($resolved);
    }
}
