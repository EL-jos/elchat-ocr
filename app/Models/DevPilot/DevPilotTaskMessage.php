<?php

namespace App\Models\DevPilot;

use Illuminate\Database\Eloquent\Model;

class DevPilotTaskMessage extends Model
{
    public $timestamps = false;

    protected $table = 'devpilot_task_messages';
    protected $fillable = ['task_id', 'role', 'created_by', 'content', 'created_at'];
    protected $casts = ['created_at' => 'datetime'];
}
