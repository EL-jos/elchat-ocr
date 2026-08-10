<?php

namespace App\Models\Mcp;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Référentiel éditorial — voir migration create_mcp_capability_playbooks_table.
 * Géré côté ELChat (admin interne), jamais exposé en écriture au tenant.
 */
class McpCapabilityPlaybook extends Model
{
    use HasUuids;

    protected $fillable = [
        'key', 'label', 'value_pitch', 'applicable_type_sites',
        'connector_slugs', 'priority_tier', 'suggested_workflow_steps', 'is_active',
    ];

    protected $casts = [
        'applicable_type_sites' => 'array',
        'connector_slugs' => 'array',
        'suggested_workflow_steps' => 'array',
        'is_active' => 'boolean',
        'priority_tier' => 'integer',
    ];

    /** true si ce playbook n'est pas restreint à des types de site précis. */
    public function isUniversal(): bool
    {
        return empty($this->applicable_type_sites);
    }
}
