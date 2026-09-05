<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KnowledgeQualityScore extends BaseModel
{
    protected $table = 'knowledge_quality_scores';
    protected $casts = [
        'recommendations' => 'array', // Laravel encode/decode automatiquement
        'coverage_score' => 'float',
        'integrity_score' => 'float',
        'retrieval_score' => 'float',
        'redundancy_score' => 'float',
        'freshness_score' => 'float',
        'global_score' => 'float',
    ];

    public function site() {
        return $this->belongsTo(Site::class);
    }
}
