<?php

namespace App\Http\Controllers\api\v5;

use App\Domain\Sales\AgentTemplateInstaller;
use App\Domain\Sales\ProspectingSourceRegistry;
use App\Http\Controllers\Controller;
use App\Jobs\RunProspectingCampaignJob;
use App\Jobs\SyncProspectToCrmJob;
use App\Models\Mcp\McpAgent;
use App\Models\Mcp\McpAgentTemplate;
use App\Models\Mcp\McpPermission;
use App\Models\Sales\Prospect;
use App\Models\Sales\ProspectingCampaign;
use App\Models\Sales\ProspectingConfig;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class SalesProspectingController extends Controller
{
    public function __construct(
        private readonly AgentTemplateInstaller $installer,
        private readonly ProspectingSourceRegistry $sources,
    ) {}

    // ── Banque d'agents ──────────────────────────────────────────────

    /** Catalogue — gratuit pour l'instant, aucune vérification d'abonnement (voir note dans le seeder/installer). */
    public function templates(Request $request, Site $site)
    {
        $this->ensureSiteAccess($request, $site);

        $templates = McpAgentTemplate::where('is_active', true)->get();
        $installedAgents = McpAgent::where('site_id', $site->id)
            ->whereNotNull('template_key')
            ->get()
            ->keyBy('template_key');

        return response()->json(['data' => $templates->map(function ($template) use ($installedAgents) {
            $installedAgent = $installedAgents->get($template->key);

            return [
                'key' => $template->key, 'name' => $template->name, 'category' => $template->category,
                'description' => $template->description, 'icon_url' => $template->icon_url,
                'installed' => $installedAgent !== null,
                'installed_agent_id' => $installedAgent?->id,
            ];
        })]);
    }

    public function installTemplate(Request $request, Site $site, string $templateKey)
    {
        $this->ensureSiteAccess($request, $site);

        $template = McpAgentTemplate::where('key', $templateKey)->where('is_active', true)->firstOrFail();

        if (McpAgent::where('site_id', $site->id)->where('template_key', $templateKey)->exists()) {
            return response()->json(['message' => 'Cet agent est déjà installé sur ce site.'], 409);
        }

        $agent = $this->installer->install($site, $template);

        return response()->json(['data' => $agent]);
    }

    public function uninstallTemplate(Request $request, Site $site, string $templateKey)
    {
        $this->ensureSiteAccess($request, $site);

        $agent = McpAgent::where('site_id', $site->id)
            ->where('template_key', $templateKey)
            ->firstOrFail();

        DB::transaction(function () use ($agent, $site) {
            $config = ProspectingConfig::where('site_id', $site->id)
                ->where('agent_id', $agent->id)
                ->first();

            if ($config) {
                $config->campaigns()
                    ->whereIn('status', ['scheduled', 'running'])
                    ->get()
                    ->each(fn (ProspectingCampaign $campaign) => $this->markCampaignStopped($campaign, 'agent_uninstalled'));
            }

            // La configuration est supprimée par la FK de l'agent. Les campagnes
            // restent conservées grâce à config_id -> NULL ON DELETE SET NULL.
            $agent->delete();
        });

        return response()->json(['status' => 'uninstalled']);
    }

    // ── Configuration (assistant en 6 étapes) ───────────────────────

    public function getConfig(Request $request, Site $site, McpAgent $agent)
    {
        $this->ensureSiteAccess($request, $site);
        $this->ensureAgentBelongsToSite($agent, $site);

        $config = ProspectingConfig::where('site_id', $site->id)->where('agent_id', $agent->id)->firstOrFail();

        return response()->json(['data' => $config]);
    }

    public function updateConfig(Request $request, Site $site, McpAgent $agent)
    {
        $this->ensureSiteAccess($request, $site);
        $this->ensureAgentBelongsToSite($agent, $site);

        $validated = $request->validate([
            'icp' => ['array'],
            'sources' => ['array', 'min:1'],
            'sources.*' => ['string', 'in:openstreetmap,web_discovery,web_search,foursquare,here,tomtom,crm_cold_contact'],
            'objective' => ['in:generate_leads,generate_meetings,identify_prospects,promote_offer'],
            'limits' => ['array'],
            'limits.max_prospects_per_campaign' => ['integer', 'min:1', 'max:500'],
            'limits.max_new_prospects_per_day' => ['integer', 'min:1', 'max:100'],
            'limits.max_outbound_actions_per_day' => ['integer', 'min:1', 'max:100'],
            'limits.max_prospects_per_run' => ['integer', 'min:1', 'max:500'],
            'limits.max_sources_per_run' => ['integer', 'min:1', 'max:10'],
            'limits.max_pages_per_prospect' => ['integer', 'min:1', 'max:20'],
            'limits.max_requests_per_source' => ['integer', 'min:1', 'max:100'],
            'limits.max_concurrent_jobs' => ['integer', 'min:1', 'max:50'],
            'limits.allowed_hours' => ['array'],
            'minimum_score' => ['integer', 'min:0', 'max:100'],
            'discovery_settings' => ['array'],
            'discovery_settings.web_seed_urls' => ['array'],
            'discovery_settings.web_seed_urls.*' => ['url', 'max:2048'],
            'autonomy_mode' => ['in:suggestion,human_approval,autonomous'],
            'schedule' => ['nullable', 'array'],
            'schedule.frequency' => ['required_with:schedule', 'in:manual,daily,every_2_days,weekly'],
            'schedule.time' => ['nullable', 'date_format:H:i'],
            'crm_connector_slug' => ['nullable', 'string'],
            'calendar_connector_slug' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        $config = ProspectingConfig::where('site_id', $site->id)->where('agent_id', $agent->id)->firstOrFail();
        DB::transaction(function () use ($config, $site, $agent, $validated) {
            $config->update($validated);

            if (array_key_exists('autonomy_mode', $validated)) {
                $this->syncAutonomyMode($site, $agent, $validated['autonomy_mode']);
            }

            if (array_key_exists('is_active', $validated)) {
                $agent->update(['is_active' => $validated['is_active']]);
            }
        });

        return response()->json(['data' => $config->fresh()]);
    }

    /**
     * Traduit le mode d'autonomie choisi en état réel du système :
     * - suggestion      : retire 'sales_hunter__send_prospect_message' des
     *   skills de l'agent — le LLM ne peut PHYSIQUEMENT pas l'appeler.
     * - human_approval  : l'ajoute aux skills, règle mcp_permissions en 'confirm'
     *   (comportement par défaut du ToolSchema — le laisse tel quel/le remet si besoin).
     * - autonomous      : l'ajoute aux skills, force la règle mcp_permissions en 'auto'.
     *
     * ⚠️ Suppose un modèle McpPermission avec les champs (site_id,
     * connector_slug, tool_name, mode, actor_scope, confirm_actor) — à
     * ajuster si les noms réels diffèrent (je n'ai pas vu ce modèle).
     */
    private function syncAutonomyMode(Site $site, McpAgent $agent, string $mode): void
    {
        $sendSkill = 'sales_hunter__send_prospect_message';
        $skills = collect($agent->skills)->reject(fn ($s) => $s === $sendSkill)->values();

        if ($mode === 'suggestion') {
            $agent->update(['skills' => $skills->all()]);

            return;
        }

        $agent->update(['skills' => $skills->push($sendSkill)->all()]);

        McpPermission::updateOrCreate(
            ['site_id' => $site->id, 'connector_slug' => 'sales_hunter', 'tool_name' => 'send_prospect_message'],
            [
                'mode' => $mode === 'autonomous' ? 'auto' : 'confirm',
                'actor_scope' => 'admin', 'confirm_actor' => 'admin',
            ],
        );
    }

    // ── Campagnes ────────────────────────────────────────────────────

    public function sourceCatalog(Request $request, Site $site)
    {
        $this->ensureSiteAccess($request, $site);

        return response()->json(['data' => $this->sources->catalog()]);
    }

    public function campaigns(Request $request, Site $site)
    {
        $this->ensureSiteAccess($request, $site);

        return response()->json(['data' => ProspectingCampaign::where('site_id', $site->id)->orderByDesc('created_at')->get()]);
    }

    public function storeCampaign(Request $request, Site $site, McpAgent $agent)
    {
        $this->ensureSiteAccess($request, $site);
        $this->ensureAgentBelongsToSite($agent, $site);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'schedule' => ['array'],
            'schedule.frequency' => ['required_with:schedule', 'in:manual,daily,every_2_days,weekly'],
            'schedule.time' => ['nullable', 'date_format:H:i'],
        ]);

        $config = ProspectingConfig::where('site_id', $site->id)->where('agent_id', $agent->id)->firstOrFail();
        abort_unless($config->is_active && $agent->is_active, 409, "Configurez et activez l'agent avant de créer une campagne.");

        $campaign = ProspectingCampaign::create([
            'id' => (string) Str::uuid(), 'site_id' => $site->id, 'config_id' => $config->id,
            'name' => $validated['name'], 'status' => 'scheduled',
            'schedule_snapshot' => $validated['schedule'] ?? $config->schedule,
            'sources_snapshot' => $config->sourceKeys(),
            'configuration_snapshot' => [
                'icp' => $config->icp, 'objective' => $config->objective, 'sources' => $config->sourceKeys(),
                'limits' => $config->limits, 'minimum_score' => $config->minimum_score,
                'discovery_settings' => $config->discovery_settings, 'crm_connector_slug' => $config->crm_connector_slug,
            ],
            'next_run_at' => $this->computeNextRun($validated['schedule'] ?? $config->schedule),
            'stats' => [],
        ]);

        return response()->json(['data' => $campaign]);
    }

    /** Déclenchement manuel — utile pour tester avant de programmer une récurrence. */
    public function runCampaign(Request $request, Site $site, ProspectingCampaign $campaign)
    {
        $this->ensureSiteAccess($request, $site);
        abort_unless($campaign->site_id === $site->id, 404);

        return $this->queueCampaignRun($site, $campaign, 'manual');
    }

    /**
     * Déclenche une exécution immédiate, même si la prochaine échéance est
     * plus tard. La campagne reste protégée contre les doubles exécutions.
     */
    public function forceRunCampaign(Request $request, Site $site, ProspectingCampaign $campaign)
    {
        $this->ensureSiteAccess($request, $site);
        abort_unless($campaign->site_id === $site->id, 404);

        return $this->queueCampaignRun($site, $campaign, 'forced');
    }

    private function queueCampaignRun(Site $site, ProspectingCampaign $campaign, string $trigger)
    {
        $campaign->loadMissing('config.agent');
        $config = $campaign->config;

        abort_if(($campaign->stats['stopped_manually'] ?? false) === true, 409, 'Cette campagne est arrêtée. Créez une nouvelle campagne pour la relancer.');
        abort_unless($config?->is_active && $config->agent?->is_active, 409, "Activez et configurez l'agent avant de lancer la campagne.");

        // La recherche et l'export restent utilisables sans CRM, comme dans
        // le prototype. La synchronisation sera marquée pending_crm lors de
        // la qualification si aucun connecteur n'est disponible.

        $queued = DB::transaction(function () use ($campaign, $trigger) {
            $locked = ProspectingCampaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            abort_if(($locked->stats['stopped_manually'] ?? false) === true, 409, 'Cette campagne est arrêtée. Créez une nouvelle campagne pour la relancer.');
            abort_if($locked->status === 'running' || $locked->runs()->where('status', 'running')->exists(), 409, 'Cette campagne est déjà en cours d’exécution.');

            $nextRunAt = $locked->next_run_at;
            if ($trigger === 'forced' && $nextRunAt?->isPast()) {
                $nextRunAt = $this->computeNextRun($locked->schedule_snapshot);
            }

            $stats = array_merge($locked->stats ?? [], [
                'last_run_trigger' => $trigger,
                'last_run_requested_at' => now()->toIso8601String(),
            ]);
            if ($trigger === 'forced') {
                $stats['last_forced_run_at'] = now()->toIso8601String();
            }

            $locked->update([
                'status' => 'running',
                'started_at' => now(),
                'completed_at' => null,
                'next_run_at' => $nextRunAt,
                'stats' => $stats,
            ]);

            return $locked->id;
        });

        try {
            RunProspectingCampaignJob::dispatch($queued, $trigger);
        } catch (Throwable $exception) {
            ProspectingCampaign::whereKey($queued)->update([
                'status' => 'failed',
                'completed_at' => now(),
                'stats' => array_merge($campaign->fresh()->stats ?? [], ['last_error' => $exception->getMessage()]),
            ]);
            throw $exception;
        }

        return response()->json([
            'status' => 'dispatched',
            'trigger' => $trigger,
            'message' => $trigger === 'forced' ? 'L’exécution forcée a été mise en file.' : 'La campagne a été mise en file.',
            'data' => ProspectingCampaign::find($queued),
        ], 202);
    }

    public function stopCampaign(Request $request, Site $site, ProspectingCampaign $campaign)
    {
        $this->ensureSiteAccess($request, $site);
        abort_unless($campaign->site_id === $site->id, 404);
        abort_if(in_array($campaign->status, ['completed', 'failed'], true), 409, 'Cette campagne est déjà terminée.');

        $this->markCampaignStopped($campaign, 'manual_stop');

        return response()->json(['data' => $campaign->fresh(), 'status' => 'stopped']);
    }

    public function destroyCampaign(Request $request, Site $site, ProspectingCampaign $campaign)
    {
        $this->ensureSiteAccess($request, $site);
        abort_unless($campaign->site_id === $site->id, 404);
        abort_if(in_array($campaign->status, ['scheduled', 'running'], true), 409, 'Arrêtez la campagne avant de la supprimer.');
        abort_if($campaign->runs()->where('status', 'running')->exists(), 409, 'La campagne est encore en cours de traitement. Réessayez après son arrêt.');

        DB::transaction(fn () => $campaign->delete());

        return response()->json(['status' => 'deleted']);
    }

    public function showCampaign(Request $request, Site $site, ProspectingCampaign $campaign)
    {
        $this->ensureSiteAccess($request, $site);
        abort_unless($campaign->site_id === $site->id, 404);

        return response()->json(['data' => $campaign->load('reports', 'runs')]);
    }

    public function campaignProspects(Request $request, Site $site, ProspectingCampaign $campaign)
    {
        $this->ensureSiteAccess($request, $site);
        abort_unless($campaign->site_id === $site->id, 404);

        return response()->json(['data' => $campaign->prospects()->orderByDesc('score')->paginate(25)]);
    }

    public function exportCampaignProspects(Request $request, Site $site, ProspectingCampaign $campaign)
    {
        $this->ensureSiteAccess($request, $site);
        abort_unless($campaign->site_id === $site->id, 404);

        $format = $request->validate(['format' => ['nullable', 'in:csv,json']])['format'] ?? 'csv';
        $prospects = $campaign->prospects()->with('evidence')->orderByDesc('score')->get();
        $filename = Str::slug($campaign->name ?: 'prospects').'.'.$format;

        if ($format === 'json') {
            return response()->json(['data' => $prospects])->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
        }

        return response()->streamDownload(function () use ($prospects): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['name', 'company', 'website', 'email', 'phone', 'address', 'contact_person', 'other_contact', 'location', 'sector', 'score', 'status', 'source', 'evidence_urls']);

            foreach ($prospects as $prospect) {
                fputcsv($handle, [
                    $prospect->name, $prospect->company, $prospect->website, $prospect->email, $prospect->phone,
                    $prospect->address, $prospect->contact_person, $prospect->other_contact,
                    $prospect->location, $prospect->sector, $prospect->score, $prospect->status, $prospect->source,
                    $prospect->evidence->pluck('source_url')->filter()->implode(' | '),
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ── Prospects ────────────────────────────────────────────────────

    public function showProspect(Request $request, Site $site, Prospect $prospect)
    {
        $this->ensureSiteAccess($request, $site);
        abort_unless($prospect->site_id === $site->id, 404);

        return response()->json(['data' => $prospect->load('messages', 'evidence')]);
    }

    public function syncProspectToCrm(Request $request, Site $site, Prospect $prospect)
    {
        $this->ensureSiteAccess($request, $site);
        abort_unless($prospect->site_id === $site->id, 404);

        SyncProspectToCrmJob::dispatch($prospect->id);

        return response()->json(['status' => 'dispatched']);
    }

    public function syncCampaignProspectsToCrm(Request $request, Site $site, ProspectingCampaign $campaign)
    {
        $this->ensureSiteAccess($request, $site);
        abort_unless($campaign->site_id === $site->id, 404);

        $terminalStatuses = ['created', 'duplicate', 'linked'];
        $prospectIds = $campaign->prospects()
            ->whereIn('status', ['qualified', 'discovered'])
            ->where(function ($query) use ($terminalStatuses): void {
                $query->whereNull('crm_sync_status')
                    ->orWhereNotIn('crm_sync_status', $terminalStatuses);
            })
            ->pluck('id');

        foreach ($prospectIds as $prospectId) {
            SyncProspectToCrmJob::dispatch((string) $prospectId);
        }

        return response()->json([
            'status' => 'dispatched',
            'dispatched' => $prospectIds->count(),
        ]);
    }

    private function computeNextRun(?array $schedule): ?Carbon
    {
        $frequency = $schedule['frequency'] ?? 'manual';
        $time = $schedule['time'] ?? '09:00';

        return match ($frequency) {
            'daily' => now()->addDay()->setTimeFromTimeString($time),
            'every_2_days' => now()->addDays(2)->setTimeFromTimeString($time),
            'weekly' => now()->addWeek()->setTimeFromTimeString($time),
            default => null, // 'manual' — jamais planifié automatiquement
        };
    }

    private function markCampaignStopped(ProspectingCampaign $campaign, string $reason): void
    {
        $campaign->runs()->where('status', 'running')->update([
            'status' => 'paused',
            'completed_at' => now(),
            'error_message' => 'La campagne a été arrêtée avant la fin de cette exécution.',
        ]);
        $campaign->update([
            'status' => 'paused',
            'next_run_at' => null,
            'stats' => array_merge($campaign->stats ?? [], [
                'stopped_manually' => true,
                'stop_reason' => $reason,
                'stopped_at' => now()->toIso8601String(),
            ]),
        ]);
    }

    private function ensureSiteAccess(Request $request, Site $site): void
    {
        $accountId = $request->user()?->ownedAccount?->id;
        abort_unless($accountId && $site->account_id === $accountId, 403);
    }

    private function ensureAgentBelongsToSite(McpAgent $agent, Site $site): void
    {
        abort_unless($agent->site_id === $site->id, 404);
    }
}
