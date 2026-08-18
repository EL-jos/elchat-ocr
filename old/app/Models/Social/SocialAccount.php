<?php

namespace App\Models\Social;

use App\Enums\Social\SocialProvider;
use App\Models\Site;
use App\Models\User;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SocialAccount extends Model
{
    use HasUuid;

    protected $fillable = [
        'site_id',
        'provider',
        'provider_account_id',
        'account_name',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'metadata',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'provider' => SocialProvider::class,
            'metadata' => 'array',
            'is_active' => 'boolean',
            'token_expires_at' => 'datetime',
        ];
    }

    public function conversations()
    {
        return $this->hasMany(
            SocialConversation::class
        );
    }

    public function events(){
        return $this->hasMany(SocialEvent::class);
    }

    /**
     * Users ELChat liés à ce canal social.
     *
     * Chaque ligne du pivot porte :
     *   - provider          → 'youtube', 'facebook', etc.
     *   - external_user_id  → identifiant natif du User SUR ce canal
     *   - external_username / external_display_name (optionnel)
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'social_account_user',  // table pivot
            'social_account_id',    // FK vers social_accounts
            'user_id'               // FK vers users
        )->withPivot([
            'provider',
            'external_user_id',
            'external_username',
            'external_display_name',
        ])->withTimestamps();
    }

    public function site(): BelongsTo {
        return $this->belongsTo(Site::class);
    }
}
