<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends BaseModel
{
    protected $casts = [
        'entities' => 'array',// ✅ nouveau
    ];

    // Charge automatiquement la pièce jointe à chaque fois qu'un Message est
    // requêté (conversation, messages()->get(), etc.), sans toucher aux
    // controllers existants (ChatController, WidgetVisitorController).
    protected $with = ['attachment'];

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

    // 🖼️ Pièces jointes (images envoyées par le visiteur pendant la conversation)
    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }

    // 🖼️ Relation singulière (au plus 1 image/upload par message dans le use-case
    // actuel), utilisée pour exposer automatiquement `attachment` dans le JSON —
    // c'est la clé que `message.ts` sait déjà désérialiser (json.attachment).
    public function attachment()
    {
        return $this->hasOne(MessageAttachment::class);
    }
}
