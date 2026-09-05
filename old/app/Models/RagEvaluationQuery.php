<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RagEvaluationQuery extends BaseModel
{
    use HasUuids;

    protected $table = "rag_evaluation_queries";

    protected $casts = [
        'expected_chunk_ids' => 'array',
        'difficulty' => 'integer',
    ];

    public function site(): BelongsTo {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function run(): BelongsTo {
        return $this->belongsTo(RagEvaluationRun::class, 'run_id');
    }
}
