<?php

namespace App\Models;

use App\Models\Mcp\McpAgent;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteGrowthAdvisorConfiguration extends BaseModel
{
    protected $table = 'website_growth_advisor_configurations';

    protected $fillable = [
        'account_id', 'site_id', 'agent_id', 'version', 'configuration_mode', 'preset_key', 'primary_objective',
        'secondary_objectives', 'priority_areas', 'enabled_sources', 'crawl_options', 'period_type',
        'custom_period_from', 'custom_period_to', 'compare_previous_period',
        'visitor_segments', 'analysis_depth', 'journey_options', 'use_visual_evidence',
        'visual_analysis_mode', 'max_representative_sessions', 'evidence_requirement',
        'require_cause_analysis', 'require_evidence', 'allow_hypotheses',
        'require_uncertainty_disclosure', 'recommendation_depth', 'implementation_detail',
        'prioritization_criteria', 'require_measurement_plan', 'primary_kpi',
        'secondary_kpis', 'conversation_options', 'knowledge_options', 'execution_mode',
        'schedule_frequency', 'schedule_time', 'next_run_at', 'is_active',
    ];

    protected $casts = [
        'secondary_objectives' => 'array',
        'priority_areas' => 'array',
        'enabled_sources' => 'array',
        'crawl_options' => 'array',
        'custom_period_from' => 'datetime',
        'custom_period_to' => 'datetime',
        'compare_previous_period' => 'boolean',
        'visitor_segments' => 'array',
        'journey_options' => 'array',
        'use_visual_evidence' => 'boolean',
        'max_representative_sessions' => 'integer',
        'require_cause_analysis' => 'boolean',
        'require_evidence' => 'boolean',
        'allow_hypotheses' => 'boolean',
        'require_uncertainty_disclosure' => 'boolean',
        'prioritization_criteria' => 'array',
        'require_measurement_plan' => 'boolean',
        'secondary_kpis' => 'array',
        'conversation_options' => 'array',
        'knowledge_options' => 'array',
        'next_run_at' => 'datetime',
        'is_active' => 'boolean',
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
