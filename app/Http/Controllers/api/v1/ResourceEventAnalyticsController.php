<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Concerns\AuthorizesSiteAccess;
use App\Http\Controllers\Controller;
use App\Models\ResourceEvent;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ResourceEventAnalyticsController extends Controller
{
    use AuthorizesSiteAccess;

    /**
     * GET site/{site}/analytics/resource-events?resource_type=cta&from=&to=
     * Retourne, par ressource : impressions, clicks, CTR, conversions.
     */
    public function index(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        $validated = $request->validate([
            'resource_type' => 'required|string|in:cta,product,page,document,image',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $query = ResourceEvent::query()
            ->where('site_id', $site->id)
            ->where('resource_type', $validated['resource_type']);

        if (!empty($validated['from'])) {
            $query->where('occurred_at', '>=', $validated['from']);
        }
        if (!empty($validated['to'])) {
            $query->where('occurred_at', '<=', $validated['to']);
        }

        [$impressionType, $clickType, $conversionType] = match ($validated['resource_type']) {
            'cta' => ['cta_impression', 'cta_click', 'cta_conversion'],
            'product' => ['product_recommended', 'product_clicked', 'purchase_completed'],
            'page' => ['page_recommended', 'page_clicked', null],
            'document' => ['document_recommended', 'document_clicked', 'document_downloaded'],
            'image' => ['image_displayed', 'image_clicked', null],
        };

        $conversionSql = $conversionType
            ? "event_type IN ('conversion', '{$conversionType}')"
            : "event_type = 'conversion'";

        $rows = $query
            ->select(
                'resource_id',
                DB::raw("MAX(label) as label"),
                DB::raw("SUM(CASE WHEN event_type IN ('impression', '{$impressionType}') THEN 1 ELSE 0 END) as impressions"),
                DB::raw("SUM(CASE WHEN event_type IN ('click', '{$clickType}') THEN 1 ELSE 0 END) as clicks"),
                DB::raw("SUM(CASE WHEN {$conversionSql} THEN 1 ELSE 0 END) as conversions")
            )
            ->groupBy('resource_id')
            ->get()
            ->map(function ($row) {
                $impressions = (int) $row->impressions;
                $clicks = (int) $row->clicks;

                return [
                    'resource_id' => $row->resource_id,
                    'label' => $row->label,
                    'impressions' => $impressions,
                    'clicks' => $clicks,
                    'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 1) : 0,
                    'conversions' => (int) $row->conversions,
                ];
            })
            ->sortByDesc('clicks')
            ->values();

        return response()->json(['data' => $rows]);
    }
}
