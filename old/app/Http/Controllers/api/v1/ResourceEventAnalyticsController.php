<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Models\ResourceEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ResourceEventAnalyticsController extends Controller
{
    /**
     * GET site/{site}/analytics/resource-events?resource_type=cta&from=&to=
     * Retourne, par ressource : impressions, clicks, CTR, conversions.
     */
    public function index(Request $request, string $siteId): JsonResponse
    {
        $validated = $request->validate([
            'resource_type' => 'required|string|in:cta,product,page,document,image',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $query = ResourceEvent::query()
            ->where('site_id', $siteId)
            ->where('resource_type', $validated['resource_type']);

        if (!empty($validated['from'])) {
            $query->where('created_at', '>=', $validated['from']);
        }
        if (!empty($validated['to'])) {
            $query->where('created_at', '<=', $validated['to']);
        }

        $rows = $query
            ->select(
                'resource_id',
                DB::raw("MAX(label) as label"),
                DB::raw("SUM(CASE WHEN event_type = 'impression' THEN 1 ELSE 0 END) as impressions"),
                DB::raw("SUM(CASE WHEN event_type = 'click' THEN 1 ELSE 0 END) as clicks"),
                DB::raw("SUM(CASE WHEN event_type = 'conversion' THEN 1 ELSE 0 END) as conversions")
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
