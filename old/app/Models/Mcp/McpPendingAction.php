<?php

namespace App\Models\Mcp;

use App\Models\Conversation;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class McpPendingAction extends Model
{
    use HasUuids;

    protected $fillable = [
        'site_id', 'conversation_id', 'connector_slug', 'tool_name', 'params',
        'confirm_actor', 'tool_call_id', 'messages_snapshot', 'status',
        'agent_id',
        'resolved_by_user_id', 'resolved_at', 'expires_at',
    ];

    protected $casts = [
        'params' => 'array',
        'messages_snapshot' => 'array',
        'resolved_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function site() { return $this->belongsTo(Site::class); }
    public function conversation() { return $this->belongsTo(Conversation::class); }
    public function resolvedBy() { return $this->belongsTo(User::class, 'resolved_by_user_id'); }
    public function agent() { return $this->belongsTo(McpAgent::class, 'agent_id'); }
}
