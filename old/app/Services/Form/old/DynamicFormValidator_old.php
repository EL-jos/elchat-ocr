<?php

namespace App\Services\Form;

use App\Enums\Form\ConditionalOperator;
use App\Enums\Form\FormFieldType;
use App\Models\Form\ChatbotForm;
use App\Models\Form\ChatbotFormField;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DynamicFormValidator
{
    /**
     * Valide les valeurs soumises pour un formulaire custom en tenant compte
     * du type de chaque champ, de ses règles de validation, et de la
     * logique conditionnelle (un champ masqué par une condition n'est
     * jamais obligatoire, même si `isRequired` est vrai).
     *
     * @throws ValidationException
     */
    public function validate(ChatbotForm $form, array $values): array
    {
        $fields = $form->fields;

        $rules = [];
        $attributes = [];

        foreach ($fields as $field) {
            $key = "values.{$field->field_key}";
            $isVisible = $this->isFieldVisible($field, $values);

            $rules[$key] = $this->rulesFor($field, $isVisible);
            $attributes[$key] = $field->label;
        }

        $validator = Validator::make(
            ['values' => $values],
            $rules,
            [],
            $attributes
        );

        $validator->validate();

        // Ne garde que les valeurs des champs visibles (les champs masqués
        // par une condition ne doivent pas être persistés même si le
        // client les a quand même envoyés).
        return collect($values)
            ->only(
                $fields->filter(fn (ChatbotFormField $f) => $this->isFieldVisible($f, $values))
                    ->pluck('field_key')
                    ->all()
            )
            ->all();
    }

    private function rulesFor(ChatbotFormField $field, bool $isVisible): array
    {
        if (! $isVisible) {
            return ['nullable'];
        }

        $rules = [$field->is_required ? 'required' : 'nullable'];
        $validation = $field->validation ?? [];

        $rules = array_merge($rules, match (FormFieldType::from($field->field_type)) {
            FormFieldType::Text => array_filter([
                'string',
                isset($validation['minLength']) ? "min:{$validation['minLength']}" : null,
                isset($validation['maxLength']) ? "max:{$validation['maxLength']}" : null,
                ! empty($validation['pattern']) ? 'regex:/' . $validation['pattern'] . '/' : null,
            ]),
            FormFieldType::Email => ['email', 'max:255'],
            FormFieldType::Phone => array_filter([
                'string',
                'max:30',
                'regex:/^[0-9+\s().-]+$/',
            ]),
            FormFieldType::Number => array_filter([
                'numeric',
                isset($validation['min']) ? "min:{$validation['min']}" : null,
                isset($validation['max']) ? "max:{$validation['max']}" : null,
            ]),
            FormFieldType::Textarea => array_filter([
                'string',
                isset($validation['minLength']) ? "min:{$validation['minLength']}" : null,
                isset($validation['maxLength']) ? "max:{$validation['maxLength']}" : null,
            ]),
            FormFieldType::Select, FormFieldType::Radio => [
                'string',
                'in:' . collect($field->options ?? [])->pluck('value')->implode(','),
            ],
            FormFieldType::CheckboxGroup => [
                'array',
            ],
            FormFieldType::Boolean => ['boolean'],
            FormFieldType::Date => ['date'],
            FormFieldType::File => array_filter([
                'string', // URL du fichier déjà uploadé (upload géré séparément avant validation)
            ]),
            default => [],
        });

        return array_values($rules);
    }

    /**
     * Évalue la logique conditionnelle d'un champ par rapport aux valeurs soumises.
     */
    private function isFieldVisible(ChatbotFormField $field, array $values): bool
    {
        $conditional = $field->conditional_logic;

        if (empty($conditional) || empty($conditional['enabled'])) {
            return true;
        }

        $dependsOnKey = $conditional['fieldKey'] ?? null;

        if (! $dependsOnKey) {
            return true;
        }

        $operator = ConditionalOperator::tryFrom($conditional['operator'] ?? 'equals') ?? ConditionalOperator::Equals;
        $expected = $conditional['value'] ?? null;
        $actual = $values[$dependsOnKey] ?? null;

        return match ($operator) {
            ConditionalOperator::Equals => (string) $actual === (string) $expected,
            ConditionalOperator::NotEquals => (string) $actual !== (string) $expected,
            ConditionalOperator::Contains => is_string($actual) && str_contains($actual, (string) $expected),
            ConditionalOperator::IsFilled => ! empty($actual),
            ConditionalOperator::IsEmpty => empty($actual),
        };
    }
}
