<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisitorIntelligenceAction extends BaseModel
{
    protected $casts = [
        'approval_required' => 'boolean', 'payload' => 'array', 'result' => 'array',
        'approved_at' => 'datetime', 'executed_at' => 'datetime',
    ];

    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
    public function session(): BelongsTo { return $this->belongsTo(VisitorSession::class, 'visitor_session_id'); }
    public function opportunity(): BelongsTo { return $this->belongsTo(VisitorOpportunity::class, 'opportunity_id'); }
    public function rule(): BelongsTo { return $this->belongsTo(VisitorIntelligenceRule::class, 'rule_id'); }
}
