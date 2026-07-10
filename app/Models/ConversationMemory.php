<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConversationMemory extends BaseModel
{
    protected $table = 'conversation_memories';
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'memory' => 'array'
    ];

    public function conversation(){
        return $this->belongsTo(Conversation::class);
    }
}
