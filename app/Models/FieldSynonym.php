<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FieldSynonym extends BaseModel
{
    protected $table = 'field_synonyms';

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

}
