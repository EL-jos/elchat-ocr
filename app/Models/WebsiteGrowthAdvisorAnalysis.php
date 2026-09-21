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
}
