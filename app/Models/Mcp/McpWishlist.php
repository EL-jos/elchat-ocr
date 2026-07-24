<?php

namespace App\Models\Mcp;

use Illuminate\Database\Eloquent\Model;

class McpWishlist extends Model
{
    protected $fillable = ['site_id', 'owner_type', 'owner_id', 'items'];
    protected $casts = ['items' => 'array'];
}
