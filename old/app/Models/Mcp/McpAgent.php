<?php

namespace App\Models\Mcp;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class McpAgent extends Model
{
    use HasUuids;

    protected $fillable = [
        'site_id', 'template_key', 'agent_type', 'name', 'objective', 'tone',
        'custom_tone_instructions', 'skills', 'workflow_ids', 'is_active', 'is_default',
        'can_proactively_engage', 'proactive_requires_approval', 'proactive_channel_scope',
    ];
    protected $casts = [
        'skills' => 'array', 'workflow_ids' => 'array', 'is_active' => 'boolean', 'is_default' => 'boolean',
        'can_proactively_engage' => 'boolean', 'proactive_requires_approval' => 'boolean', 'proactive_channel_scope' => 'array',
    ];
}
