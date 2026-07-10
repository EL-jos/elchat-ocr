<?php

namespace App\Models\Form;

use App\Enums\Form\FormFieldType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatbotFormField extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'form_id',
        'field_key',
        'label',
        'field_type',
        'placeholder',
        'help_text',
        'is_required',
        'position',
        'options',
        'validation',
        'conditional_logic',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'position' => 'integer',
        'options' => 'array',
        'validation' => 'array',
        'conditional_logic' => 'array',
    ];

    public function form(): BelongsTo
    {
        return $this->belongsTo(ChatbotForm::class, 'form_id');
    }

    public function type(): FormFieldType
    {
        return FormFieldType::from($this->field_type);
    }
}
