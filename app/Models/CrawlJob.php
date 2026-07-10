<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrawlJob extends BaseModel
{
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
    public function pages(): HasMany
    {
        return $this->hasMany(Page::class);
    }
}
