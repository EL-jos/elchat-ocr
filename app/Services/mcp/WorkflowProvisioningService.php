<?php

namespace App\Services\mcp;


use App\Domain\MCP\Capability\CapabilityActionPlaybookEngine;
use App\Domain\MCP\Capability\CapabilityResolver;
use App\Models\Mcp\McpCapability;
use App\Models\Mcp\McpCapabilityActionPlaybook;
use App\Models\Mcp\McpConnector;
use App\Models\Mcp\McpWorkflow;
use App\Models\Site;
use Illuminate\Support\Str;

/**
 * Implémente la consigne : "un workflow doit déclarer les capacités
 * requises, et chaque capacité doit pouvoir déclarer son connecteur/
 * dépendance éventuel. Lorsqu'un tenant installe un workflow, ELChat
 * vérifie les dépendances, indique celles qui manquent et permet de les
 * connecter/installer."
 *
 * La déclaration existe déjà nativement dans le schéma, sans rien
 * ajouter :
 * - Un McpWorkflow déclare ses capacités requises via `steps[].capability`.
 * - Une capacité déclare son/ses connecteur(s) requis via les préfixes de
 *   ses `tool_names` (McpCapabilityActionPlaybook).
 * Ce service ne fait que LIRE ces deux déclarations et orchestrer la
 * vérification + le provisionnement — aucune nouvelle donnée à saisir.
 */
class WorkflowProvisioningService
{
    public function __construct(
        private readonly CapabilityActionPlaybookEngine $actionPlaybookEngine,
        private readonly CapabilityResolver $resolver,
    ) {
    }

    /**
     * Vérifie l'état de chaque capacité requise par le workflow pour ce
     * site, SANS rien modifier. À afficher avant "Installer".
     *
     * @return array{workflow_ready: bool, steps: array}
     */
    public function checkDependencies(Site $site, McpWorkflow $workflow): array
    {
        $activeToolNames = collect($this->resolver->availableToolsCatalog($site))->pluck('tool_name')->all();
        $acceptedCapabilityKeys = McpCapability::where('site_id', $site->id)->pluck('key')->all();

        $steps = collect($workflow->steps)->map(function (array $step) use ($activeToolNames, $acceptedCapabilityKeys) {
            $capabilityKey = $step['capability'];
            $playbookKey = $this->underlyingPlaybookKey($capabilityKey);
            $playbook = $playbookKey ? McpCapabilityActionPlaybook::where('key', $playbookKey)->first() : null;

            if (!$playbook) {
                // Capacité référencée par le workflow mais absente du référentiel :
                // incohérence de contenu à corriger côté admin ELChat, pas côté tenant.
                return [
                    'capability' => $capabilityKey, 'label' => $step['label'] ?? $capabilityKey,
                    'optional' => (bool) ($step['optional'] ?? false),
                    'status' => 'unknown_capability', 'missing_connectors' => [],
                ];
            }

            if (in_array($capabilityKey, $acceptedCapabilityKeys, true)) {
                $status = 'ready';
            } else {
                $available = array_intersect($playbook->tool_names, $activeToolNames);
                $status = empty($available) ? 'blocked' : 'provisionable';
            }

            $missingConnectors = $status === 'ready' ? [] : $this->missingConnectorRefs($playbook->tool_names, $activeToolNames);

            return [
                'capability' => $capabilityKey, 'label' => $step['label'] ?? $playbook->label,
                'optional' => (bool) ($step['optional'] ?? false),
                'status' => $status, 'missing_connectors' => $missingConnectors,
            ];
        })->values()->all();

        // Le workflow est "prêt" si toutes les étapes NON optionnelles sont
        // ready ou provisionable (le provisionnement se fera à l'installation).
        $workflowReady = collect($steps)
            ->filter(fn ($s) => !$s['optional'])
            ->every(fn ($s) => in_array($s['status'], ['ready', 'provisionable'], true));

        return ['workflow_ready' => $workflowReady, 'steps' => $steps];
    }

    /**
     * Installe le workflow pour ce site : crée sa copie locale si c'est une
     * recette globale (site_id null), puis accepte automatiquement toutes
     * les capacités "provisionable" (au moins un connecteur déjà actif).
     * Les capacités "blocked" restent à la charge de l'admin (connecteur à
     * activer dans le marketplace) — jamais provisionnées à l'aveugle.
     *
     * @return array{workflow: McpWorkflow, dependencies: array}
     */
    public function install(Site $site, McpWorkflow $workflow): array
    {
        $localWorkflow = $workflow->site_id === $site->id
            ? $workflow
            : McpWorkflow::create([
                'id' => (string) Str::uuid(), 'site_id' => $site->id,
                'slug' => $workflow->slug . '-' . Str::random(6),
                'name' => $workflow->name, 'trigger_description' => $workflow->trigger_description,
                'steps' => $workflow->steps, 'is_active' => true,
            ]);

        foreach ($workflow->steps as $step) {
            $playbookKey = $this->underlyingPlaybookKey($step['capability']);
            if (!$playbookKey) {
                continue;
            }

            $activeToolNames = collect($this->resolver->availableToolsCatalog($site))->pluck('tool_name')->all();
            $playbook = McpCapabilityActionPlaybook::where('key', $playbookKey)->first();
            if ($playbook && !empty(array_intersect($playbook->tool_names, $activeToolNames))) {
                $this->actionPlaybookEngine->accept($site, $playbookKey);
            }
        }

        return ['workflow' => $localWorkflow, 'dependencies' => $this->checkDependencies($site, $localWorkflow)];
    }

    /** "playbook_seo-search-analytics" → "seo-search-analytics". Null si le format ne correspond pas à ce mécanisme. */
    private function underlyingPlaybookKey(string $capabilityKey): ?string
    {
        return str_starts_with($capabilityKey, 'playbook_') ? substr($capabilityKey, strlen('playbook_')) : null;
    }

    private function missingConnectorRefs(array $toolNames, array $activeToolNames): array
    {
        $missingSlugs = collect($toolNames)
            ->reject(fn ($t) => in_array($t, $activeToolNames, true))
            ->map(fn ($t) => explode('__', $t)[0])
            ->unique()->values();

        // Un slug n'est vraiment "manquant" que si AUCUN de ses tool_names
        // dans ce step n'est actif (sinon ce n'est qu'un fournisseur alternatif déjà couvert).
        $coveredSlugs = collect($toolNames)
            ->filter(fn ($t) => in_array($t, $activeToolNames, true))
            ->map(fn ($t) => explode('__', $t)[0])->unique();

        $trulyMissing = $missingSlugs->diff($coveredSlugs);
        if ($trulyMissing->isEmpty()) {
            return [];
        }

        $meta = McpConnector::whereIn('slug', $trulyMissing)->get()->keyBy('slug');
        return $trulyMissing->map(fn ($slug) => [
            'slug' => $slug, 'name' => $meta[$slug]->name ?? $slug, 'icon_url' => $meta[$slug]->icon_url ?? null,
        ])->values()->all();
    }
}
