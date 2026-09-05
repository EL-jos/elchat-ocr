<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VisitorOpportunity extends BaseModel
{
    protected $casts = [
        'evidence' => 'array', 'recommendations' => 'array', 'actions' => 'array',
        'confidence' => 'decimal:2', 'detected_at' => 'datetime',
    ];

    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
    public function session(): BelongsTo { return $this->belongsTo(VisitorSession::class, 'visitor_session_id'); }
    public function visitor(): BelongsTo { return $this->belongsTo(Visitor::class); }
    public function actions(): HasMany { return $this->hasMany(VisitorIntelligenceAction::class, 'opportunity_id'); }
}
