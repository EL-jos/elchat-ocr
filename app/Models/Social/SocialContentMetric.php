<?php

namespace App\Models\Social;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialContentMetric extends BaseModel
{
    protected $table = 'social_content_metrics';
    protected $fillable = [
        'account_id', 'site_id', 'social_account_id', 'social_content_id', 'provider',
        'metric_key', 'metric_definition', 'value', 'unit', 'period_from', 'period_to',
        'observed_at', 'source', 'availability_status', 'comparable', 'metadata', 'dedupe_key',
    ];
    protected $casts = [
        'value' => 'decimal:6', 'period_from' => 'datetime', 'period_to' => 'datetime',
        'observed_at' => 'datetime', 'comparable' => 'boolean', 'metadata' => 'array',
    ];

    public function content(): BelongsTo { return $this->belongsTo(SocialContent::class, 'social_content_id'); }
    public function socialAccount(): BelongsTo { return $this->belongsTo(SocialAccount::class); }
}
