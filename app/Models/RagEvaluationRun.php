<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RagEvaluationRun extends BaseModel
{
    use HasUuids;

    protected $table = "rag_evaluation_runs";
    protected $casts = [
        'metrics_breakdown' => 'array',
        'metrics_administrator' => 'array'
    ];

    public function site(): BelongsTo {
        return $this->belongsTo(Site::class, 'site_id');
    }
    public function results(): HasMany {
        return $this->hasMany(RagEvaluationResult::class, 'run_id');
    }
    public function queries(): HasMany {
        return $this->hasMany(RagEvaluationQuery::class, 'run_id');
    }
}
