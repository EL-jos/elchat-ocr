<?php

namespace App\Models\Social;

use App\Enums\Social\MessageDirection;
use App\Enums\Social\MessageType;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class SocialMessage extends Model
{
    use HasUuid;

    protected $fillable = [
        'social_conversation_id',
        'provider',
        'external_message_id',
        'direction',
        'content',
        'message_type',
        'generated_by_ai',
        'confidence_score',
        'metadata',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'direction' => MessageDirection::class,
            'message_type' => MessageType::class,
            'metadata' => 'array',
            'generated_by_ai' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        // Tri par défaut selon la colonne "priority" en ordre croissant
        static::addGlobalScope('order', function ($builder) {
            $builder->orderBy('created_at', 'asc');
        });
    }

    public function conversation()
    {
        return $this->belongsTo(
            SocialConversation::class,
            'social_conversation_id'
        );
    }

    public function replies(){
        return $this->hasMany(SocialReplyQueue::class, "social_message_id");
    }

    public function reply(){
        return $this->hasOne(SocialReplyQueue::class, "social_message_id");
    }

    public function isFacebookComment(): bool
    {
        return !empty($this->metadata['comment_id']);
    }

    public function isMessengerMessage(): bool
    {
        return !empty($this->metadata['sender_id']);
    }
}
