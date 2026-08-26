<?php

namespace App\Models\Sales;

use App\Models\Mcp\McpAgent;
use App\Models\Site;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProspectingConfig extends Model
{
    use HasUuids;

    protected $table = 'sales_prospecting_configs';

    protected $fillable = [
        'site_id', 'agent_id', 'icp', 'sources', 'objective', 'limits', 'discovery_settings', 'minimum_score', 'autonomy_mode',
        'schedule', 'crm_connector_slug', 'calendar_connector_slug', 'is_active',
    ];

    protected $casts = [
        'icp' => 'array', 'sources' => 'array', 'limits' => 'array', 'discovery_settings' => 'array',
        'schedule' => 'array', 'minimum_score' => 'integer', 'is_active' => 'boolean',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(McpAgent::class, 'agent_id');
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(ProspectingCampaign::class, 'config_id');
    }

    /** Limite quotidienne pour un type d'action donné, avec valeur par défaut prudente si non configurée. */
    public function limitFor(string $key, int $default = 0): int
    {
        return (int) ($this->limits[$key] ?? $default);
    }

    /** @return string[] */
    public function sourceKeys(): array
    {
        $sources = $this->sources;

        return is_array($sources) && $sources !== [] ? array_values(array_unique($sources)) : ['openstreetmap'];
    }
}
