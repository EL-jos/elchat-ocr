<?php

namespace App\Models\Payment;

use App\Models\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Subscription extends Model
{
    protected $keyType    = 'string';
    public    $incrementing = false;

    protected $fillable = [
        // Commun
        'account_id', 'plan_id',
        'billing_cycle', 'status',
        'trial_ends_at', 'current_period_start', 'current_period_end',
        'canceled_at', 'ends_at',
        'currency', 'amount', 'metadata',

        // Provider
        'payment_provider',

        // Stripe (inchangé)
        'stripe_customer_id', 'stripe_subscription_id', 'stripe_price_id',

        // PayPal (nouveau)
        'paypal_subscription_id', 'paypal_plan_id',
        'paypal_payer_id', 'paypal_order_id',
    ];

    protected $casts = [
        'trial_ends_at'        => 'datetime',
        'current_period_start' => 'datetime',
        'current_period_end'   => 'datetime',
        'canceled_at'          => 'datetime',
        'ends_at'              => 'datetime',
        'metadata'             => 'array',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?? Str::uuid());
    }

    // ─── Relations ────────────────────────────────────────────────────────────

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    // ─── Provider helpers ─────────────────────────────────────────────────────

    public function isStripe(): bool
    {
        return $this->payment_provider === 'stripe';
    }

    public function isPayPal(): bool
    {
        return $this->payment_provider === 'paypal';
    }

    // ─── Statuts ─────────────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return in_array($this->status, ['active', 'trialing']);
    }

    public function isTrialing(): bool
    {
        return $this->status === 'trialing'
            && $this->trial_ends_at
            && $this->trial_ends_at->isFuture();
    }

    public function trialExpired(): bool
    {
        return $this->status === 'trialing'
            && $this->trial_ends_at
            && $this->trial_ends_at->isPast();
    }

    public function isCanceled(): bool
    {
        return $this->status === 'canceled';
    }

    public function isPastDue(): bool
    {
        return $this->status === 'past_due';
    }

    public function isUsable(): bool
    {
        if ($this->status === 'active') return true;
        if ($this->isTrialing())        return true;

        // Annulé mais encore dans la période payée
        if ($this->status === 'canceled'
            && $this->ends_at
            && $this->ends_at->isFuture()) {
            return true;
        }

        return false;
    }

    // ─── Accesseurs ──────────────────────────────────────────────────────────

    public function trialDaysRemaining(): int
    {
        if (!$this->trial_ends_at || !$this->isTrialing()) return 0;
        return max(0, (int) now()->diffInDays($this->trial_ends_at, false));
    }

    public function daysUntilRenewal(): int
    {
        if (!$this->current_period_end) return 0;
        return max(0, (int) now()->diffInDays($this->current_period_end, false));
    }

    public function getFormattedAmountAttribute(): string
    {
        if (!$this->amount) return '—';
        $symbol = strtoupper($this->currency) === 'USD' ? '$' : '€';
        return $symbol . number_format($this->amount / 100, 2);
    }

    public function getBillingCycleLabelAttribute(): string
    {
        return $this->billing_cycle === 'annual' ? 'Annuel' : 'Mensuel';
    }

    public function getProviderLabelAttribute(): string
    {
        return match ($this->payment_provider) {
            'paypal' => 'PayPal',
            default  => 'Carte bancaire',
        };
    }
}
