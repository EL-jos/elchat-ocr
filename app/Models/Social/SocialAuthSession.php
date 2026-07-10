<?php

namespace App\Models\Social;

use App\Enums\Social\SocialProvider;
use App\Models\Account;
use App\Models\Site;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class SocialAuthSession extends Model
{
    use HasUuid;

    protected $fillable = [
        'site_id',
        'account_id',
        'provider',
        'access_token',
        'payload',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'provider' => SocialProvider::class,
            'expires_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function site()
    {
        return $this->belongsTo(Site::class);
    }
    public function account(){
        return $this->belongsTo(Account::class);
    }
}
