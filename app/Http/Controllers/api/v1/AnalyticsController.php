<?php

namespace App\Http\Controllers\api\v1;

use App\Enums\AnalyticsEventType;
use App\Http\Controllers\Concerns\AuthorizesSiteAccess;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\analytics\AnalyticsQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnalyticsController extends Controller
{
    use AuthorizesSiteAccess;

    public function __construct(private readonly AnalyticsQueryService $analytics)
    {
    }

    public function overview(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        return response()->json(['data' => $this->analytics->overview($site, $this->filters($request))]);
    }

    public function businessImpact(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        return response()->json(['data' => $this->analytics->businessImpact($site, $this->filters($request))]);
    }

    public function funnel(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $filters = $this->filters($request, [
            'steps' => ['nullable', 'array', 'min:2', 'max:10'],
            'steps.*' => ['string', Rule::in(AnalyticsEventType::values())],
        ]);

        return response()->json([
            'data' => $this->analytics->funnel($site, $filters, $filters['steps'] ?? null),
        ]);
    }

    public function knowledge(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        return response()->json(['data' => $this->analytics->knowledge($site, $this->filters($request))]);
    }

    public function agents(Request $request, Site $site): JsonResponse
    {
        return $this->executionPerformance($request, $site, 'agents');
    }

    public function workflows(Request $request, Site $site): JsonResponse
    {
        return $this->executionPerformance($request, $site, 'workflows');
    }

    public function mcp(Request $request, Site $site): JsonResponse
    {
        return $this->executionPerformance($request, $site, 'mcp');
    }

    public function recommendations(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        return response()->json(['data' => $this->analytics->recommendations($site, $this->filters($request))]);
    }

    public function anomalies(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        return response()->json(['data' => $this->analytics->anomalies($site, $this->filters($request))]);
    }

    private function executionPerformance(Request $request, Site $site, string $kind): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        return response()->json([
            'data' => $this->analytics->executionPerformance($site, $this->filters($request), $kind),
        ]);
    }

    private function filters(Request $request, array $additionalRules = []): array
    {
        return $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'channel' => ['nullable', 'string', 'max:32'],
            'source' => ['nullable', 'string', 'max:64'],
            'agent_id' => ['nullable', 'uuid'],
            'workflow_id' => ['nullable', 'uuid'],
            'event_type' => ['nullable', 'string', Rule::in(AnalyticsEventType::values())],
            ...$additionalRules,
        ]);
    }
}
