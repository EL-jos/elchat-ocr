<?php

namespace App\Models\Payment;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Coupon extends Model
{
    protected $table = 'coupons';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'code', 'type', 'value', 'duration_type', 'duration_months',
        'applies_to_modules', 'max_redemptions', 'redeemed_count',
        'starts_at', 'expires_at', 'is_active',
    ];

    protected $casts = [
        'applies_to_modules' => 'array',
        'starts_at'          => 'datetime',
        'expires_at'         => 'datetime',
        'is_active'          => 'boolean',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?? (string) Str::uuid());
    }

    public function subscriptionCoupons(): HasMany
    {
        return $this->hasMany(SubscriptionCoupon::class);
    }

    public function isValid(): bool
    {
        if (!$this->is_active) return false;
        if ($this->starts_at && $this->starts_at->isFuture()) return false;
        if ($this->expires_at && $this->expires_at->isPast()) return false;
        if ($this->max_redemptions && $this->redeemed_count >= $this->max_redemptions) return false;
        return true;
    }

    public function appliesToModule(string $moduleSlug): bool
    {
        if (empty($this->applies_to_modules)) return true; // null = tous les modules
        return in_array($moduleSlug, $this->applies_to_modules);
    }

    /**
     * Calcule le montant de la réduction (centimes) pour un montant de base donné.
     */
    public function discountFor(int $baseAmountCents): int
    {
        if ($this->type === 'percentage') {
            return (int) round($baseAmountCents * ($this->value / 100));
        }
        // fixed : value déjà en centimes
        return min($this->value, $baseAmountCents);
    }
}
