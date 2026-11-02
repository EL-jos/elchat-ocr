<?php

namespace App\Models\DevPilot;

use Illuminate\Database\Eloquent\Model;

final class DevPilotTaskActionRun extends Model
{
    protected $table = 'devpilot_task_action_runs';

    protected $fillable = [
        'task_id', 'proposal_hash', 'action_index', 'action_fingerprint', 'action_type', 'status',
        'replay_safe', 'attempts', 'result_encrypted', 'error_code', 'started_at', 'completed_at',
    ];

    protected $hidden = ['result_encrypted'];

    protected $casts = [
        'replay_safe' => 'boolean', 'attempts' => 'integer',
        'started_at' => 'datetime', 'completed_at' => 'datetime',
    ];
}
