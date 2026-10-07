<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisitorSessionReplayVideoExport extends BaseModel
{
    protected $table = 'visitor_session_replay_video_exports';

    protected $casts = [
        'progress' => 'integer',
        'file_size_bytes' => 'integer',
        'duration_ms' => 'integer',
        'frame_count' => 'integer',
        'fps' => 'integer',
        'skip_inactive' => 'boolean',
        'playback_speed' => 'float',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(VisitorSession::class, 'visitor_session_id');
    }
}
