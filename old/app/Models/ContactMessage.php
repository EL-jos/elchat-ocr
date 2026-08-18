<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class ContactMessage extends Model
{
    use HasUuid;

    protected $guarded = [];

    protected $casts = [

        'is_read' => 'boolean',

        'read_at' => 'datetime',

    ];
}
