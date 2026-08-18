<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Chunk extends BaseModel
{
    protected $casts = [
        'embedding' => 'array',
        'metadata' => 'array'
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function site(){
        return $this->belongsTo(Site::class);
    }
    public function product(){
        return $this->belongsTo(Product::class);
    }

    public function document(){
        return $this->belongsTo(Document::class);
    }
}
