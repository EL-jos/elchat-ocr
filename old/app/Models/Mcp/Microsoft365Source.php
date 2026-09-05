<?php

namespace App\Models\Mcp;

use App\Models\Site;
use Illuminate\Database\Eloquent\Model;

class Microsoft365Source extends Model
{
    protected $table = 'microsoft365_sources';

    protected $fillable = [
        'site_id', 'provider_tenant_id', 'provider_principal_id', 'provider_site_id',
        'provider_drive_id', 'provider_item_id', 'name', 'mime_type', 'web_url',
        'etag', 'permissions', 'status', 'last_seen_at', 'last_error',
    ];

    protected $casts = [
        'permissions' => 'array',
        'last_seen_at' => 'datetime',
    ];

    public function site()
    {
        return $this->belongsTo(Site::class);
    }
}
