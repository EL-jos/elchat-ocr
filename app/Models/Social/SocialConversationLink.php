<?php

namespace App\Models\Social;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class SocialConversationLink extends Model
{
    use HasUuid;
    protected $guarded = [];

    public function socialConversation()
    {
        return $this->belongsTo(SocialConversation::class);
    }

    public function conversation()
    {
        return $this->belongsTo(\App\Models\Conversation::class);
    }
}
