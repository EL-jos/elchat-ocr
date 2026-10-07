<?php

namespace App\Models\Social;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialRecommendation extends BaseModel
{
    protected $table = 'social_recommendations';
    protected $fillable = [
        'account_id', 'site_id', 'social_analysis_id', 'social_insight_id', 'title', 'problem',
        'why', 'action', 'how', 'expected_signal', 'measurement', 'action_proposal', 'status',
    ];
    protected $casts = ['action_proposal' => 'array'];
    public function insight(): BelongsTo { return $this->belongsTo(SocialInsight::class, 'social_insight_id'); }
    public function analysis(): BelongsTo { return $this->belongsTo(SocialAnalysis::class, 'social_analysis_id'); }
}
