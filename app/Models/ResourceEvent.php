<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResourceEvent extends Model
{
    use HasUuids;

    protected $table = 'resource_events';

    protected $fillable = [
        'site_id', 'conversation_id', 'message_id',
        'resource_type', 'resource_id', 'event_type',
        'action', 'label', 'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
