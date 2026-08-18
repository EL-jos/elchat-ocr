<?php

namespace App\Models\Proactive;

use App\Models\BaseModel;
use App\Models\Mcp\McpAgent;
use App\Models\Mcp\McpWorkflow;
use App\Models\Site;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProactiveCampaign extends BaseModel
{
    protected $casts = [
        'allowed_days' => 'array',
        'follow_up_intervals' => 'array',
        'metadata' => 'array',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'stop_on_reply' => 'boolean',
        'stop_on_conversion' => 'boolean',
        'stop_on_human_handoff' => 'boolean',
        'stop_on_refusal' => 'boolean',
        'stop_on_unsubscribe' => 'boolean',
    ];

    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
    public function agent(): BelongsTo { return $this->belongsTo(McpAgent::class, 'agent_id'); }
    public function workflow(): BelongsTo { return $this->belongsTo(McpWorkflow::class, 'workflow_id'); }
    public function triggers(): HasMany { return $this->hasMany(ProactiveTrigger::class, 'campaign_id'); }
    public function sequences(): HasMany { return $this->hasMany(ProactiveSequence::class, 'campaign_id'); }
    public function messages(): HasMany { return $this->hasMany(ProactiveMessage::class, 'campaign_id'); }
    public function outcomes(): HasMany { return $this->hasMany(ProactiveOutcome::class, 'campaign_id'); }
}
