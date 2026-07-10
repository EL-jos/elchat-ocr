<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnansweredQuestion extends BaseModel
{
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
