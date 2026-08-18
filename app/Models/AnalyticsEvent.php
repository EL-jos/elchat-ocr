<?php

namespace App\Models;

use App\Models\Mcp\McpAgent;
use App\Models\Mcp\McpWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticsEvent extends Model
{
    use HasUuids;

    protected $table = 'resource_events';

    protected $fillable = [
        'id', 'account_id', 'site_id', 'visitor_id', 'conversation_id', 'message_id',
        'agent_id', 'workflow_id', 'session_id', 'correlation_id', 'causation_id',
        'parent_event_id', 'event_type', 'resource_type',
        'resource_id', 'source', 'channel', 'idempotency_key', 'attribution_type',
        'value', 'currency', 'action', 'label', 'metadata', 'occurred_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'value' => 'decimal:4',
        'occurred_at' => 'datetime',
    ];

    public function scopeForSite(Builder $query, Site|string $site): Builder
    {
        return $query->where('site_id', $site instanceof Site ? $site->id : $site);
    }

    public function scopeOccurredBetween(Builder $query, $from, $to): Builder
    {
        return $query->whereBetween('occurred_at', [$from, $to]);
    }

    public function site(): BelongsTo { return $this->belongsTo(Site::class); }
    public function conversation(): BelongsTo { return $this->belongsTo(Conversation::class); }
    public function message(): BelongsTo { return $this->belongsTo(Message::class); }
    public function visitor(): BelongsTo { return $this->belongsTo(Visitor::class); }
    public function agent(): BelongsTo { return $this->belongsTo(McpAgent::class, 'agent_id'); }
    public function workflow(): BelongsTo { return $this->belongsTo(McpWorkflow::class, 'workflow_id'); }
}
