<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ConversationMemory extends BaseModel
{
    use HasUuids;

    protected $table = 'conversation_memories';
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'memory' => 'array'
    ];

    public function conversation(){
        return $this->belongsTo(Conversation::class);
    }
    // Accesseurs pratiques, alignés sur le format produit par extractStructuredMemory()
    public function preferences(): array { return $this->memory['preferences'] ?? []; }
    public function objectives(): array { return $this->memory['objectives'] ?? []; }
    public function constraints(): array { return $this->memory['constraints'] ?? []; }
    public function decisions(): array { return $this->memory['decisions'] ?? []; }
    public function userInfo(): array { return $this->memory['user_info'] ?? []; }
}
