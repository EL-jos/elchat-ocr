<?php

namespace App\Domain\MCP\Capability;

use App\Domain\MCP\Capability\Concerns\DetectsMcpFriction;
use App\Models\Mcp\{McpCapability, McpCapabilityActionPlaybook, McpCapabilitySuggestionDismissal};
use App\Models\Site;

/**
 * Recommande des COMBOS D'ACTIONS précises (ex: "google_calendar__create_event"
 * + "hubspot__create_meeting" sous une même capacité "Prise de RDV qualifié"),
 * au même connecteur ou cross-connecteur — à partir d'un référentiel éditorial
 * (mcp_capability_action_playbooks).
 *
 * Distinct de CapabilityPlaybookEngine, qui lui recommande des CONNECTEURS
 * entiers pas encore activés. Ici on suppose qu'au moins un des connecteurs
 * concernés est déjà actif : sinon la suggestion de connecteur (l'autre
 * moteur) est plus pertinente et on ne duplique pas le message.
 *
 * Alimente le bandeau de CapabilityManagerComponent. Contrairement au
 * moteur connecteurs, "accepter" une suggestion ici crée directement une
 * McpCapability — CapabilityResolver::providersFor() filtre déjà
 * dynamiquement par outils actifs, donc inclure des tool_names pas encore
 * disponibles est sans danger : la capacité se complètera d'elle-même si
 * le connecteur manquant est activé plus tard.
 */
class CapabilityActionPlaybookEngine
{
    use DetectsMcpFriction;

    private const TIER_WEIGHTS = [1 => 30, 2 => 20, 3 => 10];
    private const MAX_SUGGESTIONS = 3;

    public function __construct(private readonly CapabilityResolver $resolver)
    {
    }

    public function suggestFor(Site $site): array
    {
        $frictions = $this->detectFrictions($site);
        if (!empty($frictions)) {
            return $frictions;
        }

        $activeToolNames = collect($this->resolver->availableToolsCatalog($site))->pluck('tool_name')->all();
        $dismissed = McpCapabilitySuggestionDismissal::where('site_id', $site->id)
            ->where('kind', 'action_combo')->pluck('playbook_key')->all();
        $alreadyAccepted = McpCapability::where('site_id', $site->id)
            ->where('key', 'like', 'playbook_%')->pluck('key')
            ->map(fn ($k) => substr($k, strlen('playbook_')))->all();
        $typeSiteName = $site->type?->name;

        return McpCapabilityActionPlaybook::where('is_active', true)->get()
            ->reject(fn (McpCapabilityActionPlaybook $p) => in_array($p->key, $dismissed, true))
            ->reject(fn (McpCapabilityActionPlaybook $p) => in_array($p->key, $alreadyAccepted, true))
            ->filter(fn (McpCapabilityActionPlaybook $p) => $p->isUniversal() || in_array($typeSiteName, $p->applicable_type_sites ?? [], true))
            ->map(function (McpCapabilityActionPlaybook $p) use ($activeToolNames) {
                $available = array_values(array_intersect($p->tool_names, $activeToolNames));
                $missing = array_values(array_diff($p->tool_names, $activeToolNames));
                $completionRatio = count($p->tool_names) ? count($available) / count($p->tool_names) : 0;
                return [$p, $available, $missing, $completionRatio];
            })
            // Aucun outil déjà actif : la suggestion de connecteur (autre
            // moteur) est plus pertinente que celle-ci pour ce site.
            // ⚠️ (float) explicite : count()/count() renvoie un INT quand la
            // division est exacte (0/4 === 0, pas 0.0) — une comparaison
            // stricte à 0.0 ne rejetait donc jamais rien.
            ->reject(fn ($row) => (float) $row[3] === 0.0)
            ->map(function ($row) {
                [$p, $available, $missing, $completionRatio] = $row;
                $score = self::TIER_WEIGHTS[$p->priority_tier] ?? 10;
                $score += $completionRatio * 10;

                return [
                    'type' => 'action_suggestion',
                    'key' => $p->key,
                    'label' => $p->label,
                    'value_pitch' => $p->value_pitch,
                    'tier' => $p->priority_tier,
                    'completion_ratio' => round($completionRatio, 2),
                    'ready_now' => $completionRatio === 1.0,
                    'available_tool_count' => count($available),
                    'missing_tool_count' => count($missing),
                    'missing_connectors' => $this->missingConnectorSlugs($missing),
                    '_score' => $score,
                ];
            })
            ->sortByDesc('_score')
            ->take(self::MAX_SUGGESTIONS)
            ->map(fn ($s) => collect($s)->except('_score')->all())
            ->values()->all();
    }

    /** Crée (ou met à jour) la McpCapability correspondante — idempotent, rejouable sans doublon. */
    public function accept(Site $site, string $key): McpCapability
    {
        $playbook = McpCapabilityActionPlaybook::where('key', $key)->where('is_active', true)->firstOrFail();

        return McpCapability::updateOrCreate(
            ['site_id' => $site->id, 'key' => "playbook_{$playbook->key}"],
            ['label' => $playbook->label, 'tool_names' => $playbook->tool_names],
        );
    }

    public function dismiss(Site $site, string $key): void
    {
        McpCapabilitySuggestionDismissal::updateOrCreate(
            ['site_id' => $site->id, 'playbook_key' => $key, 'kind' => 'action_combo'],
            ['dismissed_at' => now()],
        );
    }

    /** Slugs de connecteurs distincts derrière les tool_names manquants, pour le lien "Réglages/Connecter". */
    private function missingConnectorSlugs(array $missingToolNames): array
    {
        return collect($missingToolNames)
            ->map(fn ($t) => explode('__', $t)[0])
            ->unique()->values()->all();
    }
}
