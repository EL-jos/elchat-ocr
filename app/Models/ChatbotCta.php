<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatbotCta extends BaseModel
{
    protected $table = 'chatbot_ctas';
    public function message()
    {
        return $this->belongsTo(Message::class);
    }
    public function rules(){
        return $this->hasMany(ChatBotCTARule::class, 'cta_id', 'id');
    }
}
