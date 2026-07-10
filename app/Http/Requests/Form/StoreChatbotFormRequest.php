<?php

namespace App\Http\Requests\Form;

use App\Enums\Form\ConditionalOperator;
use App\Enums\Form\FormFieldType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreChatbotFormRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'submitLabel' => ['nullable', 'string', 'max:100'],
            'successMessage' => ['nullable', 'string', 'max:500'],
            'isActive' => ['sometimes', 'boolean'],

            'fields' => ['required', 'array', 'min:1'],
            'fields.*.id' => ['nullable', 'string'],
            'fields.*.fieldKey' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'fields.*.label' => ['required', 'string', 'max:255'],
            'fields.*.fieldType' => ['required', Rule::in(FormFieldType::values())],
            'fields.*.placeholder' => ['nullable', 'string', 'max:255'],
            'fields.*.helpText' => ['nullable', 'string', 'max:255'],
            'fields.*.isRequired' => ['sometimes', 'boolean'],
            'fields.*.position' => ['nullable', 'integer', 'min:0'],

            'fields.*.options' => ['nullable', 'array'],
            'fields.*.options.*.label' => ['required_with:fields.*.options', 'string', 'max:255'],
            'fields.*.options.*.value' => ['required_with:fields.*.options', 'string', 'max:255'],

            'fields.*.validation' => ['nullable', 'array'],
            'fields.*.validation.minLength' => ['nullable', 'integer', 'min:0'],
            'fields.*.validation.maxLength' => ['nullable', 'integer', 'min:0'],
            'fields.*.validation.min' => ['nullable', 'numeric'],
            'fields.*.validation.max' => ['nullable', 'numeric'],
            'fields.*.validation.pattern' => ['nullable', 'string', 'max:255'],
            'fields.*.validation.acceptedFileTypes' => ['nullable', 'string', 'max:255'],
            'fields.*.validation.maxFileSizeMb' => ['nullable', 'integer', 'min:1', 'max:50'],

            'fields.*.conditionalLogic' => ['nullable', 'array'],
            'fields.*.conditionalLogic.enabled' => ['sometimes', 'boolean'],
            'fields.*.conditionalLogic.fieldKey' => ['nullable', 'string'],
            'fields.*.conditionalLogic.operator' => ['nullable', Rule::in(ConditionalOperator::values())],
            'fields.*.conditionalLogic.value' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $fields = collect($this->input('fields', []));

            // Un champ de type select/radio/checkbox_group doit avoir au moins 1 option
            foreach ($fields as $index => $field) {
                $type = $field['fieldType'] ?? null;

                if (in_array($type, FormFieldType::typesWithOptions() ? array_map(fn ($t) => $t->value, FormFieldType::typesWithOptions()) : [], true)
                    && empty($field['options'])) {
                    $validator->errors()->add(
                        "fields.$index.options",
                        "Le champ \"{$field['label']}\" doit avoir au moins une option."
                    );
                }
            }

            // Les clés techniques doivent être uniques au sein du formulaire (une fois auto-générées elles le seront,
            // mais on vérifie ici celles fournies explicitement par le front)
            $keys = $fields->pluck('fieldKey')->filter()->all();
            if (count($keys) !== count(array_unique($keys))) {
                $validator->errors()->add('fields', 'Les clés techniques des champs doivent être uniques.');
            }
        });
    }
}
