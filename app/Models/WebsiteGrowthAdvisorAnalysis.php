<?php

namespace App\Models;

use App\Models\Mcp\McpAgent;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteGrowthAdvisorAnalysis extends BaseModel
{
    protected $table = 'website_growth_advisor_analyses';

    protected $casts = [
        'period_from' => 'datetime',
        'period_to' => 'datetime',
        'include_external' => 'boolean',
        'configuration_snapshot' => 'array',
        'progress' => 'integer',
        'source_status' => 'array',
        'data_snapshot' => 'array',
        'result' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'representative_sessions_count' => 'integer',
        'visual_moments_count' => 'integer',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(McpAgent::class, 'agent_id');
    }

    /** @return array<string, mixed> */
    public function visualizationData(): array
    {
        $snapshot = is_array($this->data_snapshot) ? $this->data_snapshot : [];
        $kpiKeys = [
            'sessions', 'sessions_with_elchat', 'conversations', 'leads',
            'appointments', 'conversions', 'abandoned_sessions', 'engagement_rate',
        ];
        $kpis = collect(data_get($snapshot, 'overview.kpis', []))
            ->filter(fn ($metric): bool => is_array($metric) && in_array($metric['key'] ?? null, $kpiKeys, true))
            ->sortBy(fn (array $metric): int => array_search($metric['key'] ?? null, $kpiKeys, true))
            ->map(fn (array $metric): array => [
                'key' => (string) ($metric['key'] ?? ''),
                'label' => (string) ($metric['label'] ?? $metric['key'] ?? ''),
                'value' => is_numeric($metric['value'] ?? null) ? (float) $metric['value'] : null,
                'unit' => (string) ($metric['unit'] ?? 'count'),
                'available' => (bool) ($metric['available'] ?? false),
            ])->values()->all();

        return [
            'period' => data_get($snapshot, 'period', []),
            'kpis' => $kpis,
            'trend' => collect(data_get($snapshot, 'overview.trend', []))
                ->filter(fn ($point): bool => is_array($point))
                ->take(60)->values()->all(),
            'acquisition' => collect(data_get($snapshot, 'overview.acquisition', []))
                ->filter(fn ($source): bool => is_array($source))
                ->take(12)->values()->all(),
            'funnel' => collect(data_get($snapshot, 'journey.funnel', []))
                ->filter(fn ($stage): bool => is_array($stage))
                ->map(fn (array $stage): array => [
                    'key' => (string) ($stage['key'] ?? ''),
                    'label' => (string) ($stage['label'] ?? $stage['key'] ?? ''),
                    'sessions' => (int) ($stage['sessions'] ?? 0),
                ])->values()->all(),
            'drop_off' => collect(data_get($snapshot, 'journey.drop_off', []))
                ->filter(fn ($row): bool => is_array($row))
                ->take(8)->values()->all(),
            'frequent_paths' => collect(data_get($snapshot, 'journey.frequent_paths', []))
                ->filter(fn ($path): bool => is_array($path))
                ->take(5)->values()->all(),
        ];
    }
}
