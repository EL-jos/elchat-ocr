<?php

namespace App\Models\Proactive;

use App\Models\BaseModel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Mcp\McpAgent;
use App\Models\Mcp\McpWorkflow;
use App\Models\Visitor;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProactiveMessage extends BaseModel
{
    protected $casts = [
        'evidence' => 'array',
        'metadata' => 'array',
        'scheduled_at' => 'datetime',
        'decided_at' => 'datetime',
        'sent_at' => 'datetime',
        'opened_at' => 'datetime',
        'notified_at' => 'datetime',
        'clicked_at' => 'datetime',
        'replied_at' => 'datetime',
        'canceled_at' => 'datetime',
        'locked_at' => 'datetime',
    ];

    public function campaign(): BelongsTo { return $this->belongsTo(ProactiveCampaign::class, 'campaign_id'); }
    public function sequence(): BelongsTo { return $this->belongsTo(ProactiveSequence::class, 'sequence_id'); }
    public function conversation(): BelongsTo { return $this->belongsTo(Conversation::class); }
    public function visitor(): BelongsTo { return $this->belongsTo(Visitor::class); }
    public function agent(): BelongsTo { return $this->belongsTo(McpAgent::class, 'agent_id'); }
    public function workflow(): BelongsTo { return $this->belongsTo(McpWorkflow::class, 'workflow_id'); }
    public function chatMessage(): BelongsTo { return $this->belongsTo(Message::class, 'message_id'); }
    public function deliveries(): HasMany { return $this->hasMany(ProactiveDelivery::class, 'message_id'); }
}
