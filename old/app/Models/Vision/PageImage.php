<?php

namespace App\Models\Vision;

use App\Models\Chunk;
use App\Models\Page;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageImage extends Model
{
    use HasFactory;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'page_id',
        'site_id',
        'url',
        'url_hash',
        'content_hash',
        'alt',
        'context',
        'width',
        'height',
        'status',
        'description',
        'ocr_text',
        'error_message',
        'chunk_id',
    ];

    protected $casts = [
        'width' => 'integer',
        'height' => 'integer',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function chunk(): BelongsTo
    {
        return $this->belongsTo(Chunk::class, 'chunk_id');
    }
}
