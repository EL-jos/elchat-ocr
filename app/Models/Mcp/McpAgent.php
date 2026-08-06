<?php

namespace App\Models\Mcp;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class McpAgent extends Model
{
    use HasUuids;

    protected $fillable = ['site_id', 'name', 'objective', 'tone', 'custom_tone_instructions', 'skills', 'workflow_ids', 'is_active', 'is_default'];
    protected $casts = ['skills' => 'array', 'workflow_ids' => 'array', 'is_active' => 'boolean', 'is_default' => 'boolean'];
}
