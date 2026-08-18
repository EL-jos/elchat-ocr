<?php

namespace App\Models\Mcp;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class McpAgentTemplate extends Model
{
    use HasUuids;

    protected $fillable = [
        'key', 'name', 'category', 'description', 'icon_url',
        'required_module_slug', 'default_config', 'bootstrap_workflow_slugs', 'is_active',
    ];

    protected $casts = [
        'default_config' => 'array',
        'bootstrap_workflow_slugs' => 'array',
        'is_active' => 'boolean',
    ];
}
