<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends BaseModel
{
    protected $casts = [
        'entities' => 'array',// ✅ nouveau
    ];

    public static function booted()
    {
        // Tri par défaut selon la colonne "priority" en ordre croissant
        static::addGlobalScope('order', function ($builder) {
            $builder->orderBy('created_at', 'asc');
        });
    }
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ctas()
    {
        return $this->hasMany(ChatbotCta::class)
            ->orderBy('position');
    }

    public function displayedCtas()
    {
        return $this->hasMany(MessageCTA::class)
            ->orderBy('position');
    }

    public function chatFormSubmissions(){
        return $this->hasMany(ChatFormSubmission::class);
    }
}
