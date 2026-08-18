<?php

namespace App\Models\Social;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class SocialEvent extends Model
{
    use HasUuid;

    protected $fillable = [
        'social_account_id',
        'provider',
        'event_type',
        'external_event_id',
        'payload',
        'metadata',
        'processing_status',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'metadata' => 'array',
        ];
    }

    public function socialAccount(){
        return $this->belongsTo(SocialAccount::class);
    }
}
