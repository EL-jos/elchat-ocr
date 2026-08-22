<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class VisitorSession extends BaseModel
{
    protected $casts = [
        'started_at' => 'datetime', 'last_seen_at' => 'datetime', 'ended_at' => 'datetime',
        'is_new_visitor' => 'boolean', 'has_widget_interaction' => 'boolean',
        'converted' => 'boolean', 'metadata' => 'array',
    ];

    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
    public function visitor(): BelongsTo { return $this->belongsTo(Visitor::class); }
    public function summary(): HasOne { return $this->hasOne(VisitorSessionSummary::class); }
    public function opportunities(): HasMany { return $this->hasMany(VisitorOpportunity::class); }
    public function actions(): HasMany { return $this->hasMany(VisitorIntelligenceAction::class); }
    public function replayChunks(): HasMany { return $this->hasMany(VisitorSessionReplayChunk::class, 'visitor_session_id'); }
}
