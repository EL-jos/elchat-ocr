<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class UserVerification extends Model
{
    protected $guarded = [];
    //protected $dates = ['expires_at', 'used_at', 'created_at', 'updated_at'];
    public $incrementing = false;
    protected $keyType = "string";
    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Générer UUID automatiquement
    protected static function booted()
    {
        static::creating(function ($model) {

            $model->id = (string) Str::uuid();

        });
    }

    // génère un code alphanumérique 6 caractères
    public static function generateCode(): string
    {
        return Str::upper(Str::random(6));
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->lt(now());
    }

    public function markUsed()
    {
        $this->used_at = now();
        $this->save();
    }
}
