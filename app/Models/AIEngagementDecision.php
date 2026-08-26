<?php

namespace App\Models;

use App\Models\Proactive\ProactiveMessage;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIEngagementDecision extends BaseModel
{
    protected $table = 'ai_engagement_decisions';

    protected $casts = [
        'signals' => 'array',
        'context_snapshot' => 'array',
        'evaluated_at' => 'datetime',
    ];

    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
    public function visitor(): BelongsTo { return $this->belongsTo(Visitor::class); }
    public function session(): BelongsTo { return $this->belongsTo(VisitorSession::class, 'visitor_session_id'); }
    public function sourceEvent(): BelongsTo { return $this->belongsTo(AnalyticsEvent::class, 'source_event_id'); }
    public function conversation(): BelongsTo { return $this->belongsTo(Conversation::class); }
    public function proactiveMessage(): BelongsTo { return $this->belongsTo(ProactiveMessage::class); }
}
