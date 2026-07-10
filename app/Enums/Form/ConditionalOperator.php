<?php

namespace App\Enums\Form;

enum ConditionalOperator: string
{
    case Equals = 'equals';
    case NotEquals = 'not_equals';
    case Contains = 'contains';
    case IsFilled = 'is_filled';
    case IsEmpty = 'is_empty';

    /**
     * Ces opérateurs n'ont pas besoin d'une valeur de comparaison.
     */
    public function needsValue(): bool
    {
        return ! in_array($this, [self::IsFilled, self::IsEmpty], true);
    }

    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
