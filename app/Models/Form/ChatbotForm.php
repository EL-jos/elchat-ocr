<?php

namespace App\Models\Form;

use App\Models\ChatbotCta;
use App\Models\ChatFormSubmission;
use App\Models\Document;
use App\Models\Site;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatbotForm extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'site_id',
        'name',
        'description',
        'submit_label',
        'success_message',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function fields(): HasMany
    {
        return $this->hasMany(ChatbotFormField::class, 'form_id')->orderBy('position');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(ChatFormSubmission::class, 'form_id');
    }

    /**
     * CTAs actives (action = open_form) référençant ce formulaire via chatbot_ctas.value.
     */
    public function activeUsingCtas()
    {
        return ChatbotCta::query()
            ->where('site_id', $this->site_id)
            ->where('action', 'open_form')
            ->where('value', $this->id)
            ->where('is_active', true)
            ->get();
    }

    public function documents(){
        return $this->morphMany(Document::class, 'documentable');
    }
}
