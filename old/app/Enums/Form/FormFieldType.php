<?php

namespace App\Enums\Form;

enum FormFieldType: string
{
    case Text = 'text';
    case Email = 'email';
    case Phone = 'phone';
    case Number = 'number';
    case Textarea = 'textarea';
    case Select = 'select';
    case Radio = 'radio';
    case CheckboxGroup = 'checkbox_group';
    case Boolean = 'boolean';
    case Date = 'date';
    case File = 'file';

    /**
     * Types nécessitant une liste d'options (options[]).
     */
    public static function typesWithOptions(): array
    {
        return [self::Select, self::Radio, self::CheckboxGroup];
    }

    public function needsOptions(): bool
    {
        return in_array($this, self::typesWithOptions(), true);
    }

    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
