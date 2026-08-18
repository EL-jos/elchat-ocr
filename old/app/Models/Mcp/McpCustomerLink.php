<?php

namespace App\Models\Mcp;

use Illuminate\Database\Eloquent\Model;

class McpCustomerLink extends Model
{
    protected $fillable = ['site_id', 'user_id', 'connector_slug', 'external_customer_id'];
}
