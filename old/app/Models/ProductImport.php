<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductImport extends BaseModel
{
    protected $table = 'product_imports';

    protected $casts = [
        'started_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function site(){
        return $this->belongsTo(Site::class);
    }

    public function document(){
        return $this->belongsTo(Document::class);
    }
}
