<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    public function memory(): HasOne
    {
        return $this->hasOne(ConversationMemory::class);
    }

    /**
     * Toutes les soumissions de formulaire liées à cette conversation
     * (chat_form_submissions.message_id -> messages.id -> messages.conversation_id).
     */
    public function formSubmissions(): HasManyThrough
    {
        return $this->hasManyThrough(
            ChatFormSubmission::class,
            Message::class,
            'conversation_id', // clé étrangère sur messages
            'message_id',      // clé étrangère sur chat_form_submissions
            'id',              // clé locale sur conversations
            'id'               // clé locale sur messages
        );
    }
}
