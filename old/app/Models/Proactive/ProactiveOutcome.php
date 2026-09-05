<?php

namespace App\Models\Proactive;

use App\Models\AnalyticsEvent;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProactiveOutcome extends BaseModel
{
    protected $casts = [
        'occurred_at' => 'datetime',
        'value' => 'decimal:4',
        'metadata' => 'array',
    ];

    public function campaign(): BelongsTo { return $this->belongsTo(ProactiveCampaign::class, 'campaign_id'); }
    public function sequence(): BelongsTo { return $this->belongsTo(ProactiveSequence::class, 'sequence_id'); }
    public function message(): BelongsTo { return $this->belongsTo(ProactiveMessage::class, 'message_id'); }
    public function analyticsEvent(): BelongsTo { return $this->belongsTo(AnalyticsEvent::class, 'analytics_event_id'); }
}
