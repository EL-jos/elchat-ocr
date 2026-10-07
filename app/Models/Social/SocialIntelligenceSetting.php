<?php

namespace App\Models\Social;

use App\Models\BaseModel;
use App\Models\Site;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialIntelligenceSetting extends BaseModel
{
    protected $table = 'social_intelligence_settings';

    protected $fillable = [
        'account_id', 'site_id', 'sources', 'period', 'custom_from', 'custom_to',
        'comparison', 'domains', 'depth', 'frequency', 'is_active', 'next_run_at',
    ];

    protected $casts = [
        'sources' => 'array', 'domains' => 'array', 'custom_from' => 'date',
        'custom_to' => 'date', 'is_active' => 'boolean', 'next_run_at' => 'datetime',
    ];

    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
}
