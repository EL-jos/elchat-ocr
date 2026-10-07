<?php

namespace App\Models\Social;

use App\Models\BaseModel;
use App\Models\Site;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialAnalysis extends BaseModel
{
    protected $table = 'social_analyses';
    protected $fillable = [
        'account_id', 'site_id', 'requested_by', 'period_from', 'period_to', 'comparison_from',
        'comparison_to', 'configuration', 'depth', 'status', 'progress', 'phase', 'result',
        'data_quality', 'started_at', 'completed_at', 'error',
    ];
    protected $casts = [
        'period_from' => 'datetime', 'period_to' => 'datetime', 'comparison_from' => 'datetime',
        'comparison_to' => 'datetime', 'configuration' => 'array', 'result' => 'array',
        'data_quality' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime',
        'progress' => 'integer',
    ];

    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
    public function topics(): HasMany { return $this->hasMany(SocialTopic::class); }
    public function insights(): HasMany { return $this->hasMany(SocialInsight::class); }
    public function recommendations(): HasMany { return $this->hasMany(SocialRecommendation::class); }
}
