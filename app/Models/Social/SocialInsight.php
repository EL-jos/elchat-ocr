<?php

namespace App\Models\Social;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialInsight extends BaseModel
{
    protected $table = 'social_insights';
    protected $fillable = [
        'account_id', 'site_id', 'social_analysis_id', 'type', 'title', 'importance', 'observation',
        'evidence', 'correlation', 'interpretation', 'diagnosis', 'opportunity', 'recommendation',
        'implementation', 'measurement', 'confidence', 'limitations', 'status',
    ];
    protected $casts = ['evidence' => 'array', 'limitations' => 'array'];
    public function analysis(): BelongsTo { return $this->belongsTo(SocialAnalysis::class, 'social_analysis_id'); }
    public function recommendations(): HasMany { return $this->hasMany(SocialRecommendation::class, 'social_insight_id'); }
}
