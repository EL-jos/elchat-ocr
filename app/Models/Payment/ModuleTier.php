<?php

namespace App\Models\Payment;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ModuleTier extends Model
{
    protected $table = 'module_tiers';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['module_id', 'slug', 'name', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?? (string) Str::uuid());
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ModulePrice::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    /**
     * Prix pour un cycle donné (centimes EUR).
     */
    public function priceFor(string $billingCycle): ?ModulePrice
    {
        return $this->prices()->where('billing_cycle', $billingCycle)->where('is_active', true)->first();
    }
}
