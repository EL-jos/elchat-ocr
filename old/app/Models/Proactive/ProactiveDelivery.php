<?php

namespace App\Models\Proactive;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProactiveDelivery extends BaseModel
{
    protected $casts = [
        'attempted_at' => 'datetime',
        'delivered_at' => 'datetime',
        'failed_at' => 'datetime',
        'provider_response' => 'array',
    ];

    public function message(): BelongsTo { return $this->belongsTo(ProactiveMessage::class, 'message_id'); }
}
