<?php

namespace App\Models\Mcp;

use Illuminate\Database\Eloquent\Model;

class McpAuditLog extends Model
{
    protected $fillable = ['site_id', 'conversation_id', 'connector_slug', 'tool_name', 'input_params', 'output_summary', 'permission_mode', 'status', 'duration_ms', 'error_code', 'hop_number'];

    protected $casts = [
        'input_params' => 'array',
        'output_summary' => 'array',
    ];

    // Journal immuable : pas de mises à jour après création.
    public function update(array $attributes = [], array $options = [])
    {
        throw new \LogicException('Les logs d\'audit MCP sont immuables.');
    }
}
