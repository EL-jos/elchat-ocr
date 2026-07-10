<?php

namespace App\Models;

use App\Models\Form\ChatbotForm;
use App\Models\Form\ChatFormSubmissionFile;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatFormSubmission extends BaseModel
{
    use HasUuids;

    protected $table = 'chat_form_submissions';
    protected $casts = [ 'values' => 'array' ];

    public function site(){
        return $this->belongsTo(Site::class);
    }

    public function message(){
        return $this->belongsTo(Message::class);
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(ChatbotForm::class, 'form_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(ChatFormSubmissionFile::class, 'submission_id');
    }
}
