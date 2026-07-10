<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class Product extends Model
{
    protected $table = 'products';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];

    protected $casts = [
        'price' => 'float',
        'price_min' => 'float',
        'price_max' => 'float',
        'discount_price' => 'float',
        'tax_rate' => 'float',
        'stock_quantity' => 'integer',
    ];

    protected $with = ['site'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($product) {
            if (!$product->id) {
                $product->id = (string) Str::uuid();
            }
        });
    }

    public function site(){
        return $this->belongsTo(Site::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers intelligents
    |--------------------------------------------------------------------------
    */

    public function getFinalPriceAttribute()
    {
        return $this->discount_price ?? $this->price;
    }

    public function getIsInStockAttribute()
    {
        return ($this->stock_quantity > 0) || $this->stock_status === 'in_stock';
    }

    public function toIndexableArray(): array
    {
        return Arr::only($this->toArray(), [
            'product_name',
            'product_reference',
            'product_type',
            'product_category',
            'description',
            'price',
            'currency',
            'price_min',
            'price_max',
            'discount_price',
            'tax_rate',
            'short_description',
            'features',
            'brand',
            'tags',
            'keywords',
            'stock_status',
            'stock_quantity',
            'weight',
            'dimensions',
            'colors',
            'materials',
            'availability',
            //'image_url',
            //'product_url',
            //'gallery_urls',
            //'video_url',
            'status',
            'language',
            'visibility',
        ]);
    }
}
