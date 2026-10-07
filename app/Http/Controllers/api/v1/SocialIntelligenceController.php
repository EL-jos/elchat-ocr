<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Concerns\AuthorizesSiteAccess;
use App\Http\Controllers\Controller;
use App\Jobs\SocialIntelligence\{RunSocialIntelligenceAnalysisJob, SyncSocialIntelligenceJob};
use App\Models\Site;
use App\Models\Social\SocialAnalysis;
use App\SocialIntelligence\Providers\ProviderRegistry;
use App\SocialIntelligence\Services\{SocialIntelligenceConfigurationService, SocialIntelligenceQueryService};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\Rule;

class SocialIntelligenceController extends Controller
{
    use AuthorizesSiteAccess;

    public function __construct(private readonly SocialIntelligenceQueryService $query, private readonly SocialIntelligenceConfigurationService $configuration, private readonly ProviderRegistry $providers) {}

    public function capabilities(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $data = $site->socialAccounts()->whereIn('provider', config('social-intelligence.supported_providers'))->get()->map(fn ($account) => ['id' => $account->id, 'provider' => $account->provider instanceof \BackedEnum ? $account->provider->value : $account->provider, 'account_name' => $account->account_name, 'is_active' => (bool) $account->is_active, 'capabilities' => $this->providers->capabilities($account), 'last_sync' => $account->syncRuns()->latest('created_at')->first()])->values();
        return response()->json(['data' => ['supported_providers' => config('social-intelligence.supported_providers'), 'accounts' => $data]]);
    }

    public function settings(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        return response()->json(['data' => $this->configuration->settingsFor($site)]);
    }

    public function updateSettings(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $input = $request->validate(['sources' => ['nullable', 'array'], 'sources.*' => ['string', Rule::in(config('social-intelligence.supported_providers'))], 'period' => ['nullable', Rule::in(['7d','30d','90d','custom'])], 'custom_from' => ['nullable', 'date'], 'custom_to' => ['nullable', 'date', 'after_or_equal:custom_from'], 'comparison' => ['nullable', Rule::in(['previous_period','previous_year','none'])], 'domains' => ['nullable', 'array'], 'domains.*' => ['string', Rule::in(['content','engagement','audience','conversations','trends','opportunities','recommendations'])], 'depth' => ['nullable', Rule::in(['quick','standard','deep'])], 'frequency' => ['nullable', Rule::in(['manual','daily','weekly','monthly'])], 'is_active' => ['nullable', 'boolean']]);
        $settings = $this->configuration->settingsFor($site);
        $data = $this->configuration->normalize($site, array_replace($settings->only(['sources', 'period', 'custom_from', 'custom_to', 'comparison', 'domains', 'depth', 'frequency', 'is_active']), $input));
        $data['next_run_at'] = $data['frequency'] === 'manual' ? null : $this->configuration->nextRunAt($data, now());
        $settings->update($data);
        return response()->json(['data' => $settings->fresh()]);
    }

    public function overview(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $filters = $request->validate(['provider' => ['nullable', Rule::in(config('social-intelligence.supported_providers'))]]);
        return response()->json(['data' => $this->query->overview($site, $filters)]);
    }
    public function platforms(Request $request, Site $site): JsonResponse { $this->authorizeSiteAccess($request, $site); return response()->json(['data' => $this->query->platforms($site, $request->query('analysis_id'))]); }
    public function contents(Request $request, Site $site): JsonResponse { $this->authorizeSiteAccess($request, $site); return response()->json($this->query->contents($site, $request->validate(['provider' => ['nullable', Rule::in(config('social-intelligence.supported_providers'))], 'from' => ['nullable','date'], 'to' => ['nullable','date'], 'per_page' => ['nullable','integer','between:10,100']]))); }
    public function insights(Request $request, Site $site): JsonResponse { $this->authorizeSiteAccess($request, $site); return response()->json($this->query->insights($site, $request->validate(['analysis_id' => ['nullable','uuid'], 'type' => ['nullable','string','max:40'], 'per_page' => ['nullable','integer','between:10,100']]))); }
    public function showInsight(Request $request, Site $site, string $insight): JsonResponse { $this->authorizeSiteAccess($request, $site); return response()->json(['data' => $this->query->insight($site, $insight)]); }
    public function analyses(Request $request, Site $site): JsonResponse { $this->authorizeSiteAccess($request, $site); return response()->json($this->query->analyses($site)); }

    public function sync(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site); $data = $request->validate(['sources' => ['nullable','array'], 'sources.*' => ['string', Rule::in(config('social-intelligence.supported_providers'))]]);
        SyncSocialIntelligenceJob::dispatch((string) $site->id, $data['sources'] ?? null);
        return response()->json(['status' => 'queued', 'site_id' => $site->id], 202);
    }

    public function analyze(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $validated = $request->validate(['sources' => ['nullable','array'], 'sources.*' => ['string', Rule::in(config('social-intelligence.supported_providers'))], 'period' => ['nullable', Rule::in(['7d','30d','90d','custom'])], 'custom_from' => ['nullable','date'], 'custom_to' => ['nullable','date','after_or_equal:custom_from'], 'comparison' => ['nullable', Rule::in(['previous_period','previous_year','none'])], 'domains' => ['nullable','array'], 'domains.*' => ['string'], 'depth' => ['nullable', Rule::in(['quick','standard','deep'])]]);
        $settings = $this->configuration->settingsFor($site);
        $config = $this->configuration->normalize($site, array_replace($settings->only(['sources', 'period', 'custom_from', 'custom_to', 'comparison', 'domains', 'depth', 'frequency', 'is_active']), $validated)); $period = $this->configuration->period($config);
        $analysis = SocialAnalysis::create(['account_id' => $site->account_id, 'site_id' => $site->id, 'requested_by' => $request->user()?->id, 'period_from' => $period['from'], 'period_to' => $period['to'], 'comparison_from' => $period['comparisonFrom'], 'comparison_to' => $period['comparisonTo'], 'configuration' => $config, 'depth' => $config['depth'], 'status' => 'queued', 'phase' => 'queued']);
        Bus::chain([
            new SyncSocialIntelligenceJob((string) $site->id, $config['sources']),
            new RunSocialIntelligenceAnalysisJob((string) $analysis->id),
        ])->onQueue(config('social-intelligence.queue', 'analytics'))->dispatch();
        return response()->json(['data' => $analysis], 202);
    }

    public function showAnalysis(Request $request, Site $site, string $analysis): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site); $model = SocialAnalysis::where('site_id', $site->id)->with(['topics','insights.recommendations','recommendations'])->findOrFail($analysis); return response()->json(['data' => $model]);
    }
}
