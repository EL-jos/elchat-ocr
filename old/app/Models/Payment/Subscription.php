<?php

namespace App\Models\Payment;

use App\Models\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Subscription extends Model
{
    protected $table = 'subscriptions';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'account_id', 'payment_provider', 'provider_customer_id', 'provider_subscription_id',
        'status', 'billing_cycle', 'currency',
        'trial_ends_at', 'current_period_start', 'current_period_end',
        'canceled_at', 'ends_at', 'metadata',
    ];

    protected $casts = [
        'trial_ends_at'        => 'datetime',
        'current_period_start' => 'datetime',
        'current_period_end'   => 'datetime',
        'canceled_at'           => 'datetime',
        'ends_at'                => 'datetime',
        'metadata'                => 'array',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?? (string) Str::uuid());
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SubscriptionItem::class);
    }

    public function activeItems(): HasMany
    {
        return $this->items()->whereIn('status', ['trialing', 'active', 'pending_cancellation']);
    }

    public function coupons(): HasMany
    {
        return $this->hasMany(SubscriptionCoupon::class);
    }

    // ─── États ───────────────────────────────────────────────────────────────

    public function isTrialing(): bool
    {
        return $this->status === 'trialing' && $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    public function trialExpired(): bool
    {
        return $this->status === 'trialing' && $this->trial_ends_at && $this->trial_ends_at->isPast();
    }

    public function isUsable(): bool
    {
        if (in_array($this->status, ['active'])) return true;
        if ($this->isTrialing()) return true;
        if ($this->status === 'canceled' && $this->ends_at && $this->ends_at->isFuture()) return true;
        return false;
    }

    /**
     * Montant total mensuel/annuel actuel = somme des lignes actives (hors pending_cancellation
     * qui restent facturées jusqu'à la fin de la période déjà payée).
     */
    public function currentTotalCents(): int
    {
        return (int) $this->activeItems()->sum('unit_price_eur');
    }

    public function getCurrentTotalFormattedAttribute(): string
    {
        return number_format($this->currentTotalCents() / 100, 0, ',', ' ') . ' €';
    }

    /**
     * Le compte a-t-il déjà activé un module donné (par slug) — actif ou en trial ?
     */
    public function hasModule(string $moduleSlug): bool
    {
        return $this->activeItems()
            ->whereHas('module', fn ($q) => $q->where('slug', $moduleSlug))
            ->exists();
    }

    public function itemForModule(string $moduleSlug): ?SubscriptionItem
    {
        return $this->items()
            ->whereHas('module', fn ($q) => $q->where('slug', $moduleSlug))
            ->whereIn('status', ['trialing', 'active', 'pending_cancellation'])
            ->first();
    }
}
