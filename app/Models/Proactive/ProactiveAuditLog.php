<?php

namespace App\Models\Proactive;

use App\Models\BaseModel;

class ProactiveAuditLog extends BaseModel
{
    public $timestamps = false;

    protected $casts = [
        'before_state' => 'array',
        'after_state' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];
}
