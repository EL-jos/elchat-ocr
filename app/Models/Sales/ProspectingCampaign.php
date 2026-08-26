<?php

namespace App\Models\Sales;

use App\Models\Site;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProspectingCampaign extends Model
{
    use HasUuids;

    protected $table = 'sales_prospecting_campaigns';

    protected $fillable = [
        'site_id', 'config_id', 'name', 'status', 'schedule_snapshot',
        'sources_snapshot', 'configuration_snapshot', 'next_run_at', 'started_at', 'completed_at', 'stats',
    ];

    protected $casts = [
        'schedule_snapshot' => 'array', 'sources_snapshot' => 'array', 'configuration_snapshot' => 'array', 'stats' => 'array',
        'next_run_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function config(): BelongsTo
    {
        return $this->belongsTo(ProspectingConfig::class, 'config_id');
    }

    public function prospects(): HasMany
    {
        return $this->hasMany(Prospect::class, 'campaign_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(ProspectingReport::class, 'campaign_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ProspectingRun::class, 'campaign_id');
    }

    /** Configuration figée au moment de la création de la campagne. */
    public function runtimeSettings(): array
    {
        return $this->configuration_snapshot ?: [
            'icp' => $this->config?->icp ?? [],
            'objective' => $this->config?->objective,
            'sources' => $this->sources_snapshot ?: ($this->config?->sourceKeys() ?? []),
            'limits' => $this->config?->limits ?? [],
            'minimum_score' => $this->config?->minimum_score ?? 70,
            'discovery_settings' => $this->config?->discovery_settings ?? [],
            'crm_connector_slug' => $this->config?->crm_connector_slug,
        ];
    }
}
