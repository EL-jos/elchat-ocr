<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends BaseModel
{
    protected $casts = [
        'metadata' => 'array',
    ];
    
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    public function visitor()
    {
        return $this->belongsTo(Visitor::class);
    }
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
