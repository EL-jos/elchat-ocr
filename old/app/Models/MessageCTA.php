<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MessageCTA extends BaseModel
{
    use HasUuids;

    protected $table = 'message_ctas';
    public function message()
    {
        return $this->belongsTo(Message::class);
    }

    public function cta()
    {
        return $this->belongsTo(ChatbotCta::class);
    }
}
