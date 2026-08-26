<?php

namespace App\Models\Sales;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProspectingRun extends Model
{
    use HasUuids;

    protected $table = 'sales_prospecting_runs';

    protected $fillable = [
        'campaign_id', 'idempotency_key', 'status', 'stats', 'error_message', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'stats' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(ProspectingCampaign::class, 'campaign_id');
    }

    public function prospects(): HasMany
    {
        return $this->hasMany(Prospect::class, 'prospecting_run_id');
    }
}
