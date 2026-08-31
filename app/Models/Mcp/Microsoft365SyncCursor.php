<?php

namespace App\Models\Mcp;

use App\Models\Site;
use Illuminate\Database\Eloquent\Model;

class Microsoft365SyncCursor extends Model
{
    protected $table = 'microsoft365_sync_cursors';
    protected $fillable = ['site_id', 'provider_tenant_id', 'provider_drive_id', 'provider_site_id', 'delta_link', 'last_synced_at', 'last_error'];
    protected $casts = ['last_synced_at' => 'datetime'];

    public function site()
    {
        return $this->belongsTo(Site::class);
    }
}
