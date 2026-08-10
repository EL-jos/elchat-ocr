<?php

namespace App\Models\Mcp;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class McpCapabilityActionPlaybook extends Model
{
    use HasUuids;

    protected $fillable = [
        'key', 'label', 'value_pitch', 'applicable_type_sites',
        'tool_names', 'priority_tier', 'is_active',
    ];

    protected $casts = [
        'applicable_type_sites' => 'array',
        'tool_names' => 'array',
        'is_active' => 'boolean',
        'priority_tier' => 'integer',
    ];

    public function isUniversal(): bool
    {
        return empty($this->applicable_type_sites);
    }
}
