<?php

namespace App\Http\Controllers\api\v5;

use App\Http\Controllers\Concerns\AuthorizesSiteAccess;
use App\Http\Controllers\Controller;
use App\Jobs\WebsiteGrowthAdvisor\RunWebsiteGrowthAdvisorAnalysisJob;
use App\Models\Mcp\McpAgent;
use App\Models\Mcp\McpSiteConnector;
use App\Models\Site;
use App\Models\WebsiteGrowthAdvisorAnalysis;
use App\Models\WebsiteGrowthAdvisorConfiguration;
use App\Services\WebsiteGrowthAdvisor\WebsiteGrowthAdvisorConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class WebsiteGrowthAdvisorController extends Controller
{
    use AuthorizesSiteAccess;

    public function __construct(private readonly WebsiteGrowthAdvisorConfigurationService $configurations)
    {
    }

    public function configuration(Request $request, Site $site, string $agent): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $model = $this->growthAgent($site, $agent);
        $configuration = $this->configurations->forAgent($site, $model);
        $effective = $this->configurations->effective($configuration);
        if (($configuration->configuration_mode ?? null) === 'preset' && empty($configuration->preset_key)) {
            $configuration->setAttribute('preset_key', $effective['preset_key'] ?? 'lead_generation');
        }

        return response()->json($this->configurationPayload($configuration));
    }

    public function updateConfiguration(Request $request, Site $site, string $agent): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $model = $this->growthAgent($site, $agent);
        $configuration = $this->configurations->forAgent($site, $model);

        $validated = $request->validate([
            'primary_objective' => ['required', 'string', 'max:64'],
            'configuration_mode' => ['nullable', Rule::in(['preset', 'custom'])],
            'preset_key' => ['nullable', Rule::in(array_keys($this->configurations->presets()))],
            'secondary_objectives' => ['nullable', 'array'],
            'secondary_objectives.*' => ['string', 'max:64'],
            'priority_areas' => ['nullable', 'array'],
            'priority_areas.*' => ['string', 'max:64'],
            'enabled_sources' => ['required', 'array', 'min:1'],
            'enabled_sources.*' => ['string', Rule::in(['visitor_intelligence', 'conversations', 'knowledge', 'live_crawl', 'google_analytics', 'search_console'])],
            'crawl_options' => ['nullable', 'array'],
            'crawl_options.scope' => ['nullable', Rule::in(['page', 'site'])],
            'crawl_options.urls' => ['nullable', 'array', 'max:10'],
            'crawl_options.urls.*' => ['string', 'max:500'],
            'crawl_options.max_pages' => ['nullable', 'integer', 'min:1', 'max:20'],
            'crawl_options.max_depth' => ['nullable', 'integer', 'min:0', 'max:3'],
            'period_type' => ['required', Rule::in(['last_24_hours', 'last_7_days', 'last_30_days', 'last_90_days', 'custom'])],
            'custom_period_from' => ['nullable', 'date'],
            'custom_period_to' => ['nullable', 'date', 'after_or_equal:custom_period_from'],
            'compare_previous_period' => ['boolean'],
            'visitor_segments' => ['required', 'array', 'min:1'],
            'visitor_segments.*' => ['string', Rule::in(['all', 'new', 'returning', 'organic', 'paid', 'direct', 'referral'])],
            'analysis_depth' => ['required', Rule::in(['quick', 'standard', 'deep'])],
            'journey_options' => ['nullable', 'array'],
            'journey_options.*' => ['boolean'],
            'use_visual_evidence' => ['boolean'],
            'visual_analysis_mode' => ['required', Rule::in(['disabled', 'targeted', 'deep'])],
            'max_representative_sessions' => ['required', 'integer', 'min:3', 'max:50'],
            'evidence_requirement' => ['required', Rule::in(['low', 'medium', 'high'])],
            'require_cause_analysis' => ['boolean'],
            'require_evidence' => ['boolean'],
            'allow_hypotheses' => ['boolean'],
            'require_uncertainty_disclosure' => ['boolean'],
            'recommendation_depth' => ['required', Rule::in(['diagnosis_only', 'actionable', 'implementation_ready'])],
            'implementation_detail' => ['required', Rule::in(['summary', 'practical', 'technical'])],
            'prioritization_criteria' => ['nullable', 'array'],
            'prioritization_criteria.*' => ['string', 'max:64'],
            'require_measurement_plan' => ['boolean'],
            'primary_kpi' => ['nullable', 'string', 'max:64'],
            'secondary_kpis' => ['nullable', 'array'],
            'secondary_kpis.*' => ['string', 'max:64'],
            'conversation_options' => ['nullable', 'array'],
            'conversation_options.*' => ['boolean'],
            'knowledge_options' => ['nullable', 'array'],
            'knowledge_options.*' => ['boolean'],
            'execution_mode' => ['required', Rule::in(['manual', 'scheduled'])],
            'schedule_frequency' => ['nullable', Rule::in(['daily', 'weekly', 'monthly'])],
            'schedule_time' => ['nullable', 'date_format:H:i'],
            'is_active' => ['boolean'],
        ]);

        if (($validated['period_type'] ?? null) === 'custom' && (empty($validated['custom_period_from']) || empty($validated['custom_period_to']))) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'custom_period_from' => 'Une période personnalisée nécessite une date de début et une date de fin.',
            ]);
        }
        if (($validated['execution_mode'] ?? null) === 'scheduled' && empty($validated['schedule_frequency'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'schedule_frequency' => 'Une fréquence est nécessaire pour une exécution planifiée.',
            ]);
        }

        $mode = $validated['configuration_mode'] ?? ($configuration->configuration_mode ?: 'custom');
        $presetKey = $validated['preset_key'] ?? $configuration->preset_key;
        if ($mode === 'preset') {
            abort_unless(is_string($presetKey) && $presetKey !== '', 422, 'Sélectionnez un modèle de configuration Growth Advisor.');
            $manualFields = array_diff_key($validated, array_flip(['configuration_mode', 'preset_key']));
            $validated = array_merge($this->configurations->preset($presetKey), $manualFields, [
                'configuration_mode' => 'preset',
                'preset_key' => $presetKey,
            ]);
        } else {
            $validated['configuration_mode'] = 'custom';
            $validated['preset_key'] = null;
        }

        $values = $this->configurations->normalize($validated, $configuration);
        $values['version'] = ((int) $configuration->version) + 1;
        $values['next_run_at'] = $this->configurations->scheduleNextRun($values);
        $configuration->fill($values)->save();

        return response()->json($this->configurationPayload($configuration->fresh()));
    }

    public function index(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $this->repairTerminalAnalyses((string) $site->id);

        $analyses = WebsiteGrowthAdvisorAnalysis::query()
                ->where('site_id', $site->id)
                ->latest('created_at')
                ->limit(20)
                ->get()
                ->map(fn (WebsiteGrowthAdvisorAnalysis $analysis): array => $this->analysisPayload($analysis))
                ->values();

        return response()->json(['data' => $analyses]);
    }

    public function store(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $this->repairTerminalAnalyses((string) $site->id);

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'include_external' => ['nullable', 'boolean'],
        ]);

        $agent = McpAgent::query()
            ->where('site_id', $site->id)
            ->whereIn('template_key', ['website_growth_advisor', 'website-growth-advisor'])
            ->first();
        abort_unless($agent, 422, 'Installez Website Growth Advisor depuis la Banque d’agents avant de lancer une analyse.');
        $configuration = $this->configurations->forAgent($site, $agent);
        $effective = $this->configurations->effective($configuration);
        ['from' => $from, 'to' => $to] = $this->configurations->resolvePeriod(
            $effective,
            $validated['from'] ?? null,
            $validated['to'] ?? null,
        );

        $running = WebsiteGrowthAdvisorAnalysis::query()
            ->where('site_id', $site->id)
            ->whereIn('status', ['queued', 'running'])
            ->latest('created_at')
            ->first();
        if ($running) {
            return response()->json([
                'message' => 'Une analyse Growth Advisor est déjà en cours pour ce site.',
                'data' => $this->analysisPayload($running),
            ], 202);
        }

        $analysis = WebsiteGrowthAdvisorAnalysis::query()->create([
            'id' => (string) Str::uuid(),
            'account_id' => $site->account_id,
            'site_id' => $site->id,
            'agent_id' => $agent->id,
            'period_from' => $from,
            'period_to' => $to,
            'include_external' => (bool) ($validated['include_external'] ?? true),
            'configuration_snapshot' => array_merge($effective, [
                'configuration_id' => (string) $configuration->id,
                'configuration_version' => (int) $configuration->version,
                'run_include_external' => (bool) ($validated['include_external'] ?? true),
            ]),
            'status' => 'queued',
            'progress' => 0,
            'phase' => 'queued',
            'progress_message' => 'Analyse placée dans la file d’attente.',
            'source_status' => [],
            'representative_sessions_count' => 0,
            'visual_moments_count' => 0,
        ]);

        RunWebsiteGrowthAdvisorAnalysisJob::dispatch($analysis->id);

        return response()->json(['data' => $this->analysisPayload($analysis)], 202);
    }

    public function show(Request $request, Site $site, string $analysis): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $this->repairTerminalAnalyses((string) $site->id, $analysis);
        $model = WebsiteGrowthAdvisorAnalysis::query()
            ->where('site_id', $site->id)
            ->findOrFail($analysis);

        return response()->json(['data' => $this->analysisPayload($model)]);
    }

    public function destroy(Request $request, Site $site, string $analysis): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $model = WebsiteGrowthAdvisorAnalysis::query()
            ->where('site_id', $site->id)
            ->findOrFail($analysis);

        if (in_array($model->status, ['queued', 'running'], true)) {
            return response()->json([
                'message' => 'Une analyse en cours ne peut pas être supprimée.',
            ], 409);
        }

        $model->delete();

        return response()->json(['message' => 'Analyse Growth Advisor supprimée.']);
    }

    /** @return array<string, mixed> */
    private function analysisPayload(WebsiteGrowthAdvisorAnalysis $analysis): array
    {
        $payload = $analysis->makeHidden(['data_snapshot'])->toArray();
        $snapshot = is_array($analysis->configuration_snapshot) ? $analysis->configuration_snapshot : [];
        $mode = $snapshot['configuration_mode'] ?? null;
        $presetKey = $mode === 'custom'
            ? null
            : (is_string($snapshot['preset_key'] ?? null) ? $snapshot['preset_key'] : ($snapshot['primary_objective'] ?? null));
        $preset = is_string($presetKey) ? ($this->configurations->presets()[$presetKey] ?? null) : null;

        $payload['configuration_mode'] = $mode;
        $payload['configuration_preset_key'] = $preset ? $presetKey : null;
        $payload['configuration_preset_label'] = $mode === 'custom'
            ? 'Configuration personnalisée'
            : ($preset['label'] ?? 'Configuration précédente');
        $payload['visualization'] = $analysis->visualizationData();

        return $payload;
    }

    private function repairTerminalAnalyses(string $siteId, ?string $analysisId = null): void
    {
        $terminalQuery = function () use ($siteId, $analysisId) {
            return WebsiteGrowthAdvisorAnalysis::query()
                ->where('site_id', $siteId)
                ->when($analysisId, fn ($query) => $query->whereKey($analysisId))
                ->whereIn('status', ['queued', 'running'])
                ->where(function ($query): void {
                    $query->whereNotNull('result')
                        ->orWhereNotNull('completed_at')
                        ->orWhere('progress', '>=', 100);
                });
        };

        $terminalValues = [
            'status' => 'ready',
            'progress' => 100,
            'phase' => 'completed',
            'progress_message' => 'Analyse Growth Advisor terminée.',
        ];

        $terminalQuery()->whereNull('completed_at')->update($terminalValues + ['completed_at' => now()]);
        $terminalQuery()->whereNotNull('completed_at')->update($terminalValues);
    }

    private function growthAgent(Site $site, string $agentId): McpAgent
    {
        $agent = McpAgent::query()
            ->where('site_id', $site->id)
            ->whereKey($agentId)
            ->whereIn('template_key', ['website_growth_advisor', 'website-growth-advisor'])
            ->first();
        abort_unless($agent, 404, 'Agent Website Growth Advisor introuvable pour ce site.');
        return $agent;
    }

    /** @return array<string, mixed> */
    private function configurationPayload(WebsiteGrowthAdvisorConfiguration $configuration): array
    {
        return [
            'data' => $configuration,
            'meta' => [
                'supported_sources' => [
                    'visitor_intelligence', 'conversations', 'knowledge', 'live_crawl', 'google_analytics', 'search_console',
                ],
                'supported_schedule_frequencies' => ['daily', 'weekly', 'monthly'],
                'source_availability' => $this->sourceAvailability((string) $configuration->site_id),
                'configuration_presets' => $this->configurations->presets(),
                'configuration_version' => (int) $configuration->version,
            ],
        ];
    }

    /** @return array<string, array{available: bool, reason: string|null}> */
    private function sourceAvailability(string $siteId): array
    {
        $connected = McpSiteConnector::query()
            ->where('site_id', $siteId)
            ->where('status', 'connected')
            ->with('mcpConnector:id,slug')
            ->get()
            ->mapWithKeys(fn (McpSiteConnector $connector): array => [
                (string) data_get($connector, 'mcpConnector.slug') => true,
            ]);

        return [
            'visitor_intelligence' => ['available' => true, 'reason' => null],
            'conversations' => ['available' => true, 'reason' => null],
            'knowledge' => ['available' => true, 'reason' => null],
            'live_crawl' => ['available' => true, 'reason' => null],
            'google_analytics' => [
                'available' => (bool) ($connected['google_analytics'] ?? false),
                'reason' => ($connected['google_analytics'] ?? false) ? null : 'Connecteur Google Analytics non connecté.',
            ],
            'search_console' => [
                'available' => (bool) ($connected['google_search_console'] ?? false),
                'reason' => ($connected['google_search_console'] ?? false) ? null : 'Connecteur Search Console non connecté.',
            ],
        ];
    }
}
