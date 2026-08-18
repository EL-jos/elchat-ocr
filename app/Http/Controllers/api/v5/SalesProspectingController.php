<?php

namespace App\Http\Controllers\api\v5;

use App\Domain\Sales\AgentTemplateInstaller;
use App\Http\Controllers\Controller;
use App\Jobs\RunProspectingCampaignJob;
use App\Models\Mcp\{McpAgent, McpAgentTemplate, McpPermission};
use App\Models\Sales\{ProspectingCampaign, ProspectingConfig, Prospect};
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SalesProspectingController extends Controller
{
    public function __construct(private readonly AgentTemplateInstaller $installer)
    {
    }

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
            'objective' => ['in:generate_leads,generate_meetings,identify_prospects,promote_offer'],
            'limits' => ['array'],
            'limits.max_prospects_per_campaign' => ['integer', 'min:1', 'max:500'],
            'limits.max_new_prospects_per_day' => ['integer', 'min:1', 'max:100'],
            'limits.max_outbound_actions_per_day' => ['integer', 'min:1', 'max:100'],
            'limits.allowed_hours' => ['array'],
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

        RunProspectingCampaignJob::dispatch($campaign->id);

        return response()->json(['status' => 'dispatched']);
    }

    public function showCampaign(Request $request, Site $site, ProspectingCampaign $campaign)
    {
        $this->ensureSiteAccess($request, $site);
        abort_unless($campaign->site_id === $site->id, 404);

        return response()->json(['data' => $campaign->load('reports')]);
    }

    public function campaignProspects(Request $request, Site $site, ProspectingCampaign $campaign)
    {
        $this->ensureSiteAccess($request, $site);
        abort_unless($campaign->site_id === $site->id, 404);

        return response()->json(['data' => $campaign->prospects()->orderByDesc('score')->paginate(25)]);
    }

    // ── Prospects ────────────────────────────────────────────────────

    public function showProspect(Request $request, Site $site, Prospect $prospect)
    {
        $this->ensureSiteAccess($request, $site);
        abort_unless($prospect->site_id === $site->id, 404);

        return response()->json(['data' => $prospect->load('messages')]);
    }

    private function computeNextRun(?array $schedule): ?\Illuminate\Support\Carbon
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
