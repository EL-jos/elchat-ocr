<?php

namespace App\Models\Sales;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProspectEvidence extends Model
{
    use HasUuids;

    protected $table = 'sales_prospect_evidence';

    protected $fillable = [
        'prospect_id', 'kind', 'source_key', 'source_url', 'field', 'value', 'confidence', 'observed_at', 'metadata',
    ];

    protected $casts = ['value' => 'array', 'metadata' => 'array', 'observed_at' => 'datetime', 'confidence' => 'float'];

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class, 'prospect_id');
    }
}
