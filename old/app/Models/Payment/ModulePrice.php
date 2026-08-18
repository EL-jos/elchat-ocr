<?php

namespace App\Models\Payment;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ModulePrice extends Model
{
    protected $table = 'module_prices';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['module_tier_id', 'billing_cycle', 'price_eur', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?? (string) Str::uuid());
    }

    public function moduleTier(): BelongsTo
    {
        return $this->belongsTo(ModuleTier::class);
    }

    public function getPriceEurFormattedAttribute(): string
    {
        return number_format($this->price_eur / 100, 0, ',', ' ') . ' €';
    }
}
