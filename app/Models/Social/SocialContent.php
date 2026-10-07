<?php

namespace App\Models\Social;

use App\Models\BaseModel;
use App\Models\Site;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialContent extends BaseModel
{
    protected $table = 'social_contents';
    protected $fillable = [
        'account_id', 'site_id', 'social_account_id', 'provider', 'external_content_id',
        'content_type', 'title', 'body', 'permalink', 'published_at', 'first_seen_at',
        'last_synced_at', 'availability_status', 'metadata',
    ];
    protected $casts = [
        'metadata' => 'array', 'published_at' => 'datetime', 'first_seen_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
    public function account(): BelongsTo { return $this->belongsTo(\App\Models\Account::class); }
    public function socialAccount(): BelongsTo { return $this->belongsTo(SocialAccount::class); }
    public function metrics(): HasMany { return $this->hasMany(SocialContentMetric::class); }
}
