<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RagEvaluationResult extends BaseModel
{
    use HasUuids;

    protected $table = "rag_evaluation_results";

    protected $casts = [

        'retrieved_chunks' => 'array',
        'vector_results' => 'array',
        'keyword_results' => 'array',
        'hybrid_results' => 'array',
        'reranked_chunks' => 'array',

        'retrieval_recall' => 'float',
        'mrr' => 'float',
        'ndcg' => 'float',

        'faithfulness' => 'float',
        'groundedness' => 'float',
        'answer_relevance' => 'float',
    ];

    public function site(): BelongsTo {
        return $this->belongsTo(Site::class, 'site_id');
    }
    public function run(): BelongsTo {
        return $this->belongsTo(RagEvaluationRun::class, 'run_id');
    }
}
