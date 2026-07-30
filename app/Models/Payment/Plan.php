<?php

namespace App\Models\Payment;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Plan extends Model
{
    protected $keyType    = 'string';
    public    $incrementing = false;

    protected $fillable = [
        'name', 'slug', 'description',
        // Stripe
        'stripe_price_monthly', 'stripe_price_annual',
        // PayPal (nouveau)
        'paypal_plan_monthly', 'paypal_plan_annual',
        // Prix
        'price_monthly_eur', 'price_annual_eur',
        // Limites
        'max_sites', 'max_social_networks_per_site',
        'max_messages_per_month', 'max_chunks', 'max_tokens',
        // Flags
        'has_sla', 'has_white_label', 'is_enterprise',
        'is_active', 'sort_order',
    ];

    protected $casts = [
        'has_sla'        => 'boolean',
        'has_white_label'=> 'boolean',
        'is_enterprise'  => 'boolean',
        'is_active'      => 'boolean',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?? Str::uuid());
    }

    // ─── Relations ────────────────────────────────────────────────────────────

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    // ─── Provider ID helpers ──────────────────────────────────────────────────

    /**
     * Stripe Price ID selon le cycle.
     */
    public function getStripePriceId(string $billingCycle): ?string
    {
        return $billingCycle === 'annual'
            ? $this->stripe_price_annual
            : $this->stripe_price_monthly;
    }

    /**
     * PayPal Plan ID selon le cycle.
     */
    public function getPayPalPlanId(string $billingCycle): ?string
    {
        return $billingCycle === 'annual'
            ? $this->paypal_plan_annual
            : $this->paypal_plan_monthly;
    }

    /**
     * Vérifie si le plan est prêt pour PayPal.
     */
    public function hasPayPalPlans(): bool
    {
        return !empty($this->paypal_plan_monthly) && !empty($this->paypal_plan_annual);
    }

    // ─── Prix accesseurs ──────────────────────────────────────────────────────

    public function getMonthlyPriceEurAttribute(): float
    {
        return $this->price_monthly_eur / 100;
    }

    public function getAnnualPriceEurAttribute(): float
    {
        return $this->price_annual_eur / 100;
    }

    public function getAnnualTotalEurAttribute(): float
    {
        return ($this->price_annual_eur * 12) / 100;
    }

    public function getAnnualSavingsEurAttribute(): float
    {
        return (($this->price_monthly_eur - $this->price_annual_eur) * 12) / 100;
    }

    public function getFormattedTokensAttribute(): string
    {
        $millions = $this->max_tokens / 1_000_000;
        return $millions >= 1
            ? number_format($millions, 0) . 'M'
            : number_format($this->max_tokens / 1_000, 0) . 'K';
    }

    public function getFormattedChunksAttribute(): string
    {
        if ($this->max_chunks >= 1_000_000) {
            return number_format($this->max_chunks / 1_000_000, 0) . 'M';
        }
        return number_format($this->max_chunks / 1_000, 0) . 'K';
    }
}
