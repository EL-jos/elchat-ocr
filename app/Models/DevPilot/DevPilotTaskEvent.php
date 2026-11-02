<?php

namespace App\Models\DevPilot;

use Illuminate\Database\Eloquent\Model;

class DevPilotTaskEvent extends Model
{
    public $timestamps = false;

    protected $table = 'devpilot_task_events';
    protected $fillable = ['task_id', 'kind', 'message', 'details', 'created_at'];
    protected $casts = ['details' => 'array', 'created_at' => 'datetime'];
}
