<?php

namespace App\Models\Form;

use App\Models\ChatFormSubmission;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatFormSubmissionFile extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'submission_id',
        'field_key',
        'file_name',
        'file_url',
        'mime_type',
        'size_bytes',
        'created_at',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(ChatFormSubmission::class, 'submission_id');
    }
}
