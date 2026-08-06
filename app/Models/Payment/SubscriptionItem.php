<?php

namespace App\Models\Payment;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SubscriptionItem extends Model
{
    protected $table = 'subscription_items';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'subscription_id', 'module_id', 'module_tier_id',
        'unit_price_eur', 'billing_cycle', 'status',
        'activated_at', 'canceled_at', 'access_ends_at',
    ];

    protected $casts = [
        'activated_at'    => 'datetime',
        'canceled_at'     => 'datetime',
        'access_ends_at'  => 'datetime',
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

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function moduleTier(): BelongsTo
    {
        return $this->belongsTo(ModuleTier::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SubscriptionItemEvent::class);
    }

    // ─── États ───────────────────────────────────────────────────────────────

    public function isEffectivelyActive(): bool
    {
        // pending_cancellation reste actif jusqu'à access_ends_at
        return in_array($this->status, ['trialing', 'active'])
            || ($this->status === 'pending_cancellation' && $this->access_ends_at?->isFuture());
    }

    public function logEvent(string $type, ?array $previousState = null, ?array $newState = null): SubscriptionItemEvent
    {
        return $this->events()->create([
            'event_type'      => $type,
            'previous_state'  => $previousState,
            'new_state'       => $newState,
        ]);
    }
}
