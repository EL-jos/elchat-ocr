<?php

namespace App\Models\Sales;

use App\Models\Message;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProspectMessage extends Model
{
    use HasUuids;

    protected $table = 'sales_prospect_messages';

    protected $fillable = [
        'prospect_id', 'message_id', 'channel', 'direction', 'status', 'content',
        'intent', 'external_message_id', 'in_reply_to_external_id',
    ];

    public function prospect(): BelongsTo { return $this->belongsTo(Prospect::class, 'prospect_id'); }
    public function message(): BelongsTo { return $this->belongsTo(Message::class); }
}
