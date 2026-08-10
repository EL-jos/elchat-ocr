<?php

namespace App\Domain\MCP\Capability;

use App\Models\Mcp\{McpCapabilityPlaybook, McpCapabilitySuggestionDismissal, McpConnector};
use App\Models\Site;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Domain\MCP\Capability\Concerns\DetectsMcpFriction; // 🆕 import

/**
 * Recommande à l'admin des CONNECTEURS PAS ENCORE ACTIVÉS à forte valeur
 * ajoutée pour son site, à partir d'un référentiel éditorial curé à la main
 * (mcp_capability_playbooks) — jamais un algo qui brasse des catégories.
 *
 * Distinct de CapabilityResolver::suggestFromToolTags(), qui lui ne fait que
 * regrouper des outils DÉJÀ actifs sous un nom métier.
 *
 * Règle d'or : si l'existant du site est cassé (erreurs répétées sur des
 * connecteurs déjà connectés), on ne propose RIEN de nouveau — on signale
 * le problème à corriger en premier. Ajouter un connecteur de plus à un
 * site qui n'arrive déjà pas à utiliser les siens n'est jamais une bonne
 * suggestion.
 */
class CapabilityPlaybookEngine
{
    use DetectsMcpFriction; // 🆕
    /** Erreurs consécutives sur 30 jours à partir desquelles un connecteur est jugé "en friction". */

    private const TIER_WEIGHTS = [1 => 30, 2 => 20, 3 => 10];
    private const MAX_SUGGESTIONS = 3;

    /**
     * @return array{type: 'fix_first'|'suggestion', ...}[]
     * Soit uniquement des items 'fix_first' (si friction détectée), soit
     * jusqu'à MAX_SUGGESTIONS items 'suggestion' — jamais les deux mélangés.
     */
    public function suggestFor(Site $site): array
    {
        $frictions = $this->detectFrictions($site);
        if (!empty($frictions)) {
            return $frictions;
        }

        $activeSlugs = $this->activeConnectorSlugs($site);
        $dismissed = McpCapabilitySuggestionDismissal::where('site_id', $site->id)
            ->where('kind', 'connector_combo')->pluck('playbook_key')->all(); // 🆕 + where kind
        $typeSiteName = $site->type?->name;

        return McpCapabilityPlaybook::where('is_active', true)
            ->get()
            ->reject(fn (McpCapabilityPlaybook $p) => in_array($p->key, $dismissed, true))
            ->filter(fn (McpCapabilityPlaybook $p) => $p->isUniversal() || in_array($typeSiteName, $p->applicable_type_sites ?? [], true))
            ->map(function (McpCapabilityPlaybook $p) use ($activeSlugs) {
                $required = $p->connector_slugs;
                $missing = array_values(array_diff($required, $activeSlugs));
                $completionRatio = empty($required) ? 0 : (count($required) - count($missing)) / count($required);
                return [$p, $missing, $completionRatio];
            })
            // Déjà entièrement satisfait : rien à suggérer.
            ->reject(fn ($row) => empty($row[1]))
            ->map(function ($row) {
                [$p, $missing, $completionRatio] = $row;
                $score = self::TIER_WEIGHTS[$p->priority_tier] ?? 10;
                $score += $completionRatio * 10; // finir > commencer

                return [
                    'type' => 'suggestion',
                    'key' => $p->key,
                    'label' => $p->label,
                    'value_pitch' => $p->value_pitch,
                    'tier' => $p->priority_tier,
                    'completion_ratio' => round($completionRatio, 2),
                    'missing_connectors' => $this->connectorRefs($missing),
                    'suggested_workflow_steps' => $p->suggested_workflow_steps,
                    '_score' => $score,
                ];
            })
            ->sortByDesc('_score')
            ->take(self::MAX_SUGGESTIONS)
            ->map(fn ($s) => collect($s)->except('_score')->all())
            ->values()
            ->all();
    }

    public function dismiss(Site $site, string $playbookKey): void
    {
        McpCapabilitySuggestionDismissal::updateOrCreate(
            ['site_id' => $site->id, 'playbook_key' => $playbookKey, 'kind' => 'connector_combo'], // 🆕 + kind
            ['dismissed_at' => now()],
        );
    }

    // ── Aides ────────────────────────────────────────────────────────

    private function activeConnectorSlugs(Site $site): array
    {
        return $site->mcpSiteConnectors()->where('status', 'connected')
            ->with('mcpConnector')->get()
            ->pluck('mcpConnector.slug')->filter()->values()->all();
    }

    private function connectorRefs(array $slugs): array
    {
        $meta = McpConnector::whereIn('slug', $slugs)->get()->keyBy('slug');
        return collect($slugs)->map(fn ($slug) => [
            'slug' => $slug, 'name' => $meta[$slug]->name ?? $slug, 'icon_url' => $meta[$slug]->icon_url ?? null,
        ])->values()->all();
    }
}
