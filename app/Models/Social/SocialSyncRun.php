<?php

namespace App\Models\Social;

use App\Models\BaseModel;
use App\Models\Site;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialSyncRun extends BaseModel
{
    protected $table = 'social_sync_runs';
    protected $fillable = [
        'account_id', 'site_id', 'social_account_id', 'provider', 'status', 'started_at',
        'finished_at', 'contents_seen', 'contents_written', 'metrics_written',
        'interactions_seen', 'capabilities', 'data_quality', 'error',
    ];
    protected $casts = [
        'started_at' => 'datetime', 'finished_at' => 'datetime', 'capabilities' => 'array',
        'data_quality' => 'array', 'contents_seen' => 'integer', 'contents_written' => 'integer',
        'metrics_written' => 'integer', 'interactions_seen' => 'integer',
    ];

    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
    public function socialAccount(): BelongsTo { return $this->belongsTo(SocialAccount::class); }
}
