<?php

namespace App\Models\Sales;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProspectingReport extends Model
{
    use HasUuids;

    protected $table = 'sales_prospecting_reports';

    protected $fillable = ['campaign_id', 'generated_at', 'stats', 'insights'];

    protected $casts = ['generated_at' => 'datetime', 'stats' => 'array', 'insights' => 'array'];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(ProspectingCampaign::class, 'campaign_id');
    }
}
