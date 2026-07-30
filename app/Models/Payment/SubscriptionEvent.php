<?php

namespace App\Models\Payment;

use App\Models\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SubscriptionEvent extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'subscription_id', 'account_id',
        'stripe_event_id', 'event_type', 'payload',
        'status', 'error_message', 'stripe_created_at',
    ];

    protected $casts = [
        'payload'           => 'array',
        'stripe_created_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($model) => $model->id = $model->id ?? Str::uuid());
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
