<?php

namespace App\Http\Controllers\api\v5;

use App\Http\Controllers\Concerns\AuthorizesSiteAccess;
use App\Http\Controllers\Controller;
use App\Jobs\WebsiteGrowthAdvisor\RunWebsiteGrowthAdvisorAnalysisJob;
use App\Models\Mcp\McpAgent;
use App\Models\Site;
use App\Models\WebsiteGrowthAdvisorAnalysis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class WebsiteGrowthAdvisorController extends Controller
{
    use AuthorizesSiteAccess;

    public function index(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        $analyses = WebsiteGrowthAdvisorAnalysis::query()
                ->where('site_id', $site->id)
                ->latest('created_at')
                ->limit(20)
                ->get()
                ->map(fn (WebsiteGrowthAdvisorAnalysis $analysis) => $analysis->makeHidden('data_snapshot'))
                ->values();

        return response()->json(['data' => $analyses]);
    }

    public function store(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'include_external' => ['nullable', 'boolean'],
        ]);

        $to = ! empty($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : now()->endOfDay();
        $from = ! empty($validated['from']) ? Carbon::parse($validated['from'])->startOfDay() : $to->copy()->subDays(28)->startOfDay();
        if ($from->gt($to)) [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];

        $agent = McpAgent::query()
            ->where('site_id', $site->id)
            ->whereIn('template_key', ['website_growth_advisor', 'website-growth-advisor'])
            ->first();
        abort_unless($agent, 422, 'Installez Website Growth Advisor depuis la Banque d’agents avant de lancer une analyse.');

        $running = WebsiteGrowthAdvisorAnalysis::query()
            ->where('site_id', $site->id)
            ->whereIn('status', ['queued', 'running'])
            ->latest('created_at')
            ->first();
        if ($running) {
            return response()->json([
                'message' => 'Une analyse Growth Advisor est déjà en cours pour ce site.',
                'data' => $running,
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
            'status' => 'queued',
            'source_status' => [],
            'representative_sessions_count' => 0,
            'visual_moments_count' => 0,
        ]);

        RunWebsiteGrowthAdvisorAnalysisJob::dispatch($analysis->id);

        return response()->json(['data' => $analysis], 202);
    }

    public function show(Request $request, Site $site, string $analysis): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $model = WebsiteGrowthAdvisorAnalysis::query()
            ->where('site_id', $site->id)
            ->findOrFail($analysis);

        return response()->json(['data' => $model]);
    }
}
