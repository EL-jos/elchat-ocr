<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteSecurityBaseline extends BaseModel
{
    protected $table = 'website_security_baselines';

    protected $fillable = ['account_id', 'site_id', 'audit_id', 'snapshot', 'snapshot_hash', 'is_active'];

    protected $casts = ['snapshot' => 'array', 'is_active' => 'boolean'];

    public function audit(): BelongsTo
    {
        return $this->belongsTo(WebsiteSecurityAudit::class, 'audit_id');
    }
}
