<?php

namespace App\Models\DevPilot;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class DevPilotTerminalSession extends Model
{
    use HasUuids;

    protected $table = 'devpilot_terminal_sessions';

    protected $fillable = [
        'id', 'site_id', 'project_id', 'task_id', 'status', 'output_encrypted',
        'pending_input_encrypted', 'input_sequence', 'consumed_input_sequence',
    ];

    protected $casts = [
        'input_sequence' => 'integer',
        'consumed_input_sequence' => 'integer',
    ];

}
