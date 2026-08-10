<?php

namespace App\Models\Mcp;

use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class McpCapabilitySuggestionDismissal extends Model
{
    public $timestamps = false;

    protected $fillable = ['site_id', 'playbook_key', 'kind', 'dismissed_at']; // 🆕 + 'kind'
    protected $casts = ['dismissed_at' => 'datetime'];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
