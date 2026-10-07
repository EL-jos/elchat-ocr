<?php

namespace App\Models\Social;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialTopic extends BaseModel
{
    protected $table = 'social_topics';
    protected $fillable = [
        'account_id', 'site_id', 'social_analysis_id', 'provider', 'source_type', 'topic_key',
        'label', 'frequency', 'interaction_count', 'change_percent', 'status', 'confidence', 'evidence',
    ];
    protected $casts = ['change_percent' => 'decimal:4', 'evidence' => 'array'];
    public function analysis(): BelongsTo { return $this->belongsTo(SocialAnalysis::class, 'social_analysis_id'); }
}
