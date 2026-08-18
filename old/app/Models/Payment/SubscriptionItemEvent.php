<?php

namespace App\Models\Payment;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SubscriptionItemEvent extends Model
{
    protected $table = 'subscription_item_events';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false; // seulement created_at (useCurrent en DB)

    protected $fillable = ['subscription_item_id', 'event_type', 'previous_state', 'new_state'];

    protected $casts = [
        'previous_state' => 'array',
        'new_state'      => 'array',
        'created_at'     => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function ($m) {
            $m->id = $m->id ?? (string) Str::uuid();
            $m->created_at = $m->created_at ?? now();
        });
    }

    public function subscriptionItem(): BelongsTo
    {
        return $this->belongsTo(SubscriptionItem::class);
    }
}
