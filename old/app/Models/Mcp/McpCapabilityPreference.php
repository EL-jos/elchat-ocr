<?php

namespace App\Models\Mcp;

use Illuminate\Database\Eloquent\Model;

class McpCapabilityPreference extends Model
{
    protected $fillable = ['site_id', 'capability', 'connector_slug'];
}
