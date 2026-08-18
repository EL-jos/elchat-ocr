<?php

namespace App\Models\Mcp;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class McpWorkflow extends Model
{
    use HasUuids;

    protected $fillable = ['site_id', 'slug', 'name', 'trigger_description', 'steps', 'is_active'];
    protected $casts = ['steps' => 'array', 'is_active' => 'boolean'];
}
