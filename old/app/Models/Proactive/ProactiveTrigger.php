<?php

namespace App\Models\Proactive;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProactiveTrigger extends BaseModel
{
    protected $casts = [
        'conditions' => 'array',
        'schedule' => 'array',
        'is_active' => 'boolean',
    ];

    public function campaign(): BelongsTo { return $this->belongsTo(ProactiveCampaign::class, 'campaign_id'); }
}
