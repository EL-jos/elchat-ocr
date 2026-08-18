<?php

namespace App\Models\Social;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class SocialReplyQueue extends Model
{
    use HasUuid;
    protected $guarded = [];

    public function socialMessage(){
        return $this->belongsTo(SocialMessage::class, "social_message_id");
    }
}
