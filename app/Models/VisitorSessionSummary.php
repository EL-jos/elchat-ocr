<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisitorSessionSummary extends BaseModel
{
    protected $casts = [
        'friction_points' => 'array', 'purchase_signals' => 'array',
        'unresolved_questions' => 'array', 'important_pages' => 'array',
        'important_ctas' => 'array', 'abandonment_signals' => 'array',
        'evidence' => 'array', 'generated_at' => 'datetime',
    ];

    public function session(): BelongsTo { return $this->belongsTo(VisitorSession::class, 'visitor_session_id'); }
    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
}
