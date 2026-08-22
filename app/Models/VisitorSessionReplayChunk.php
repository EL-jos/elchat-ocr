<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisitorSessionReplayChunk extends BaseModel
{
    protected $casts = [
        'first_event_at' => 'datetime',
        'last_event_at' => 'datetime',
    ];

    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
    public function session(): BelongsTo { return $this->belongsTo(VisitorSession::class, 'visitor_session_id'); }
}
