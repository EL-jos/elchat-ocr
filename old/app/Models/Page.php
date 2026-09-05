<?php

namespace App\Models;

use App\Models\Vision\PageImage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Page extends BaseModel
{
    protected $casts = [
        'is_indexed' => 'boolean',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function crawlJob(): BelongsTo
    {
        return $this->belongsTo(CrawlJob::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class);
    }
    public function images(): HasMany
    {
        return $this->hasMany(PageImage::class);
    }
}
