<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatBotCTARule extends BaseModel
{
    protected $table = "chatbot_cta_rules";

    public function cta(){
        return $this->belongsTo(ChatbotCta::class, 'cta_id', 'id');
    }
}
