<?php

namespace App\Models\Vision;

use Illuminate\Database\Eloquent\Model;

class ImageAnalysisCache extends Model
{
    protected $table = 'image_analysis_cache';

    public $incrementing = false;
    protected $keyType = 'string';
    protected $primaryKey = 'content_hash';

    protected $fillable = [
        'content_hash',
        'description',
        'ocr_text',
        'is_decorative',
        'model',
        'hits',
    ];

    protected $casts = [
        'is_decorative' => 'boolean',
        'hits' => 'integer',
    ];
}
