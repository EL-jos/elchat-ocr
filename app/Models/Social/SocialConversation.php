<?php

namespace App\Models\Social;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class SocialConversation extends Model
{
    use HasUuid;

    protected $fillable = [
        'site_id',
        'social_account_id',
        'provider',
        'external_user_id',
        'external_username',
        'external_display_name',
        'context_type',
        'context_id',
        'source_object_id',
        'metadata',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'last_message_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        // Tri par défaut selon la colonne "priority" en ordre croissant
        static::addGlobalScope('order', function ($builder) {
            $builder->orderBy('created_at', 'desc');
        });
    }

    public function socialAccount()
    {
        return $this->belongsTo(
            SocialAccount::class
        );
    }

    public function messages()
    {
        return $this->hasMany(
            SocialMessage::class
        );
    }
}
