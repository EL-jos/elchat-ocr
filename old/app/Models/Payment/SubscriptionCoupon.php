<?php

namespace App\Models\Payment;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SubscriptionCoupon extends Model
{
    protected $table = 'subscription_coupons';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'subscription_id', 'coupon_id', 'provider_coupon_ref', 'applied_at', 'expires_at',
    ];

    protected $casts = [
        'applied_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?? (string) Str::uuid());
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function isStillValid(): bool
    {
        return !$this->expires_at || $this->expires_at->isFuture();
    }
}
