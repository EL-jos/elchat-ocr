<?php

namespace App\Models\DevPilot;

use App\Models\Mcp\McpAgent;
use App\Models\Site;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DevPilotTask extends Model
{
    use HasUuids;

    protected $table = 'devpilot_tasks';

    protected $fillable = [
        'site_id', 'project_id', 'agent_id', 'connection_id', 'connection_protocol', 'connection_name',
        'target_snapshot_hash', 'project_environment', 'project_root', 'requested_by', 'instruction', 'status', 'progress',
        'plan', 'proposed_changes_encrypted', 'proposal_hash', 'rollback_snapshot_encrypted', 'result', 'error_code', 'cancel_requested',
        'approved_by', 'approved_at', 'proposal_expires_at', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'plan' => 'array', 'result' => 'array',
        'cancel_requested' => 'boolean', 'approved_at' => 'datetime', 'proposal_expires_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime',
    ];

    protected $hidden = ['proposed_changes_encrypted', 'rollback_snapshot_encrypted', 'target_snapshot_hash'];

    public function site() { return $this->belongsTo(Site::class); }
    public function project() { return $this->belongsTo(DevPilotProject::class, 'project_id'); }
    public function agent() { return $this->belongsTo(McpAgent::class, 'agent_id'); }
    public function events() { return $this->hasMany(DevPilotTaskEvent::class, 'task_id')->orderBy('id'); }
    public function messages() { return $this->hasMany(DevPilotTaskMessage::class, 'task_id')->orderBy('id'); }
    public function attachments() { return $this->hasMany(DevPilotTaskAttachment::class, 'task_id')->orderBy('created_at')->orderBy('id'); }
}
