<?php

namespace App\Models;

use App\Models\Mcp\McpAgent;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteSecurityAudit extends BaseModel
{
    protected $table = 'website_security_audits';

    protected $fillable = [
        'account_id', 'site_id', 'agent_id', 'trigger', 'status', 'progress', 'phase', 'progress_message',
        'configuration_snapshot', 'scope_report', 'inventory', 'findings', 'comparison', 'ai_analysis', 'warnings',
        'error_message', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'progress' => 'integer', 'configuration_snapshot' => 'array', 'scope_report' => 'array',
        'inventory' => 'array', 'findings' => 'array', 'comparison' => 'array', 'ai_analysis' => 'array', 'warnings' => 'array',
        'started_at' => 'datetime', 'completed_at' => 'datetime',
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
