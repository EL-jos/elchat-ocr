<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteSecurityManifest extends BaseModel
{
    protected $table = 'website_security_manifests';

    protected $fillable = [
        'account_id', 'site_id', 'agent_id', 'ecosystem', 'package_manager', 'lockfile_name',
        'manifest_name', 'fingerprint', 'component_count', 'components', 'cyclonedx',
    ];

    protected $casts = ['component_count' => 'integer', 'components' => 'array', 'cyclonedx' => 'array'];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
