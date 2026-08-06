<?php

namespace App\Models\Mcp;

use App\Models\Site;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class McpCapability extends Model
{
    use HasUuids;
    protected $fillable = ['site_id', 'key', 'label', 'tool_names'];
    protected $casts = ['tool_names' => 'array'];

    public function site(){
        return $this->belongsTo(Site::class);
    }
}
