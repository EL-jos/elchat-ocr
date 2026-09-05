<?php

namespace App\Models\Proactive;

use App\Models\BaseModel;
use App\Models\Conversation;
use App\Models\Social\SocialConversation;
use App\Models\Visitor;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProactiveSequence extends BaseModel
{
    protected $casts = [
        'next_scheduled_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'replied_at' => 'datetime',
        'converted_at' => 'datetime',
        'stopped_at' => 'datetime',
        'context_snapshot' => 'array',
        'evidence' => 'array',
        'metadata' => 'array',
    ];

    public function campaign(): BelongsTo { return $this->belongsTo(ProactiveCampaign::class, 'campaign_id'); }
    public function conversation(): BelongsTo { return $this->belongsTo(Conversation::class); }
    public function visitor(): BelongsTo { return $this->belongsTo(Visitor::class); }
    public function socialConversation(): BelongsTo { return $this->belongsTo(SocialConversation::class); }
    public function messages(): HasMany { return $this->hasMany(ProactiveMessage::class, 'sequence_id'); }
}
