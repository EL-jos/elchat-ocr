<?php

namespace App\Models;

use App\Models\Mcp\McpAgent;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteSecurityConfiguration extends BaseModel
{
    protected $table = 'website_security_configurations';

    protected $fillable = [
        'account_id', 'site_id', 'agent_id', 'version', 'audit_mode', 'analysis_depth', 'ai_interpretation',
        'checks', 'execution_mode', 'schedule_frequency', 'schedule_time', 'next_run_at', 'is_active',
        'authorized_scope', 'scope_authorized_by', 'scope_authorized_at',
    ];

    protected $casts = [
        'version' => 'integer', 'ai_interpretation' => 'boolean', 'checks' => 'array', 'authorized_scope' => 'array',
        'scope_authorized_at' => 'datetime', 'next_run_at' => 'datetime', 'is_active' => 'boolean',
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
