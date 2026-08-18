<?php

namespace App\Models\Payment;

use App\Models\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PaymentEvent extends Model
{
    protected $table = 'payment_events';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'subscription_id', 'account_id', 'provider', 'provider_event_id',
        'event_type', 'payload', 'status', 'error_message',
    ];

    protected $casts = ['payload' => 'array'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?? (string) Str::uuid());
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
