<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VisitorIntelligenceRule extends BaseModel
{
    protected $casts = [
        'conditions' => 'array', 'action' => 'array', 'limits' => 'array',
        'approval_required' => 'boolean', 'audience' => 'array', 'schedule' => 'array',
        'is_active' => 'boolean', 'last_triggered_at' => 'datetime',
    ];

    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
    public function actions(): HasMany { return $this->hasMany(VisitorIntelligenceAction::class, 'rule_id'); }
}
