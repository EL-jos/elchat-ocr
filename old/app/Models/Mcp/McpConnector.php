<?php

namespace App\Models\Mcp;

use Illuminate\Database\Eloquent\Model;

class McpConnector extends Model
{
    protected $fillable = ['slug', 'name', 'category', 'adapter_class', 'auth_type', 'default_scopes', 'available_tools', 'is_active', 'icon_url', 'description'];

    protected $casts = [
        'default_scopes' => 'array',
        'available_tools' => 'array',
        'is_active' => 'boolean',
    ];

    public function siteConnectors()
    {
        return $this->hasMany(McpSiteConnector::class);
    }
}
