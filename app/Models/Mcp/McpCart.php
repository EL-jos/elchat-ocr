<?php

namespace App\Models\Mcp;

use Illuminate\Database\Eloquent\Model;

class McpCart extends Model
{
    protected $fillable = ['site_id', 'owner_type', 'owner_id', 'items', 'coupon_code'];
    protected $casts = ['items' => 'array'];
}
