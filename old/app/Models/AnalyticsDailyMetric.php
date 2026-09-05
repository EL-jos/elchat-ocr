<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnalyticsDailyMetric extends Model
{
    protected $fillable = [
        'account_id', 'site_id', 'metric_date', 'event_type', 'source', 'channel',
        'agent_id', 'workflow_id', 'attribution_type', 'currency', 'dimension_key',
        'event_count', 'unique_visitors', 'unique_conversations', 'value_sum',
    ];

    protected $casts = [
        'metric_date' => 'date',
        'event_count' => 'integer',
        'unique_visitors' => 'integer',
        'unique_conversations' => 'integer',
        'value_sum' => 'decimal:4',
    ];
}
