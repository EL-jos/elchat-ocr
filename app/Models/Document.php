<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Document extends BaseModel
{
    protected $casts = [
        'file_size' => 'integer',
        'index_revision' => 'integer',
        'last_indexed_at' => 'datetime',
    ];

    public static function booted()
    {
        static::creating(function (Document $document) {
            $document->id ??= (string) Str::uuid();
        });

        // Tri par défaut selon la colonne "priority" en ordre croissant
        static::addGlobalScope('order', function ($builder) {
            $builder->orderBy('priority', 'asc');
        });
    }

    public function documentable(){
        return $this->morphTo();
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class);
    }

    public function productImports(): HasMany
    {
        return $this->hasMany(ProductImport::class);
    }

    public function sitemapCrawlJobs(): HasMany
    {
        return $this->hasMany(CrawlJob::class, 'source_document_id');
    }
}
