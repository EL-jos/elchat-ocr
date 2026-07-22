<?php

namespace App\Models\Mcp;

use App\Models\Site;
use Illuminate\Database\Eloquent\Model;

class McpPermission extends Model
{
    protected $fillable = ['site_id', 'connector_slug', 'tool_name', 'mode', 'daily_call_limit'];

    public function site()
    {
        return $this->belongsTo(Site::class);
    }
}
