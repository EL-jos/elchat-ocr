<?php

namespace App\Models\Payment;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Module extends Model
{
    protected $table = 'modules';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'slug', 'name', 'description', 'marketing_description', 'icon',
        'is_core', 'requires_tier', 'billing_type', 'included_in_trial',
        'sort_order', 'is_active',
    ];

    protected $casts = [
        'is_core'            => 'boolean',
        'requires_tier'      => 'boolean',
        'included_in_trial'  => 'boolean',
        'is_active'          => 'boolean',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?? (string) Str::uuid());
    }

    public function tiers(): HasMany
    {
        return $this->hasMany(ModuleTier::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    public function isContactSales(): bool
    {
        return $this->billing_type === 'contact_sales';
    }

    /**
     * Tier par défaut à utiliser pour ce module (le premier par sort_order).
     * Pour Core : le tier unique 'default'.
     * Pour les autres : 'basic' en général.
     */
    public function defaultTier(): ?ModuleTier
    {
        return $this->tiers()->active()->orderBy('sort_order')->first();
    }
}
