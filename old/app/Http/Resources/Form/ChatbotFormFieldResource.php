<?php

namespace App\Http\Resources\Form;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatbotFormFieldResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fieldKey' => $this->field_key,
            'label' => $this->label,
            'fieldType' => $this->field_type,
            'placeholder' => $this->placeholder,
            'helpText' => $this->help_text,
            'isRequired' => (bool) $this->is_required,
            'position' => $this->position,
            'options' => $this->options ?? [],
            'validation' => $this->validation,
            'conditionalLogic' => $this->conditional_logic,
        ];
    }
}
