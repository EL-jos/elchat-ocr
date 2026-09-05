<?php

namespace App\Domain\Proactive;

use Illuminate\Support\Arr;

class ProactiveConditionEvaluator
{
    public function evaluate(array $conditions, array $context, string $mode = 'all'): bool
    {
        if ($conditions === []) {
            return true;
        }

        $results = array_map(
            fn ($condition) => is_array($condition) ? $this->evaluateCondition($condition, $context) : false,
            $conditions,
        );

        return strtolower($mode) === 'any'
            ? in_array(true, $results, true)
            : !in_array(false, $results, true);
    }

    private function evaluateCondition(array $condition, array $context): bool
    {
        if (isset($condition['all']) && is_array($condition['all'])) {
            return $this->evaluate($condition['all'], $context, 'all');
        }
        if (isset($condition['any']) && is_array($condition['any'])) {
            return $this->evaluate($condition['any'], $context, 'any');
        }

        $field = (string) ($condition['field'] ?? '');
        $operator = strtolower((string) ($condition['operator'] ?? 'eq'));
        $expected = $condition['value'] ?? null;
        $exists = Arr::has($context, $field);
        $actual = data_get($context, $field);

        return match ($operator) {
            'exists' => $exists && $actual !== null,
            'not_exists' => !$exists || $actual === null,
            'eq', '=' => $actual == $expected,
            'strict_eq', '===' => $actual === $expected,
            'ne', '!=' => $actual != $expected,
            'gt', '>' => is_numeric($actual) && is_numeric($expected) && $actual > $expected,
            'gte', '>=' => is_numeric($actual) && is_numeric($expected) && $actual >= $expected,
            'lt', '<' => is_numeric($actual) && is_numeric($expected) && $actual < $expected,
            'lte', '<=' => is_numeric($actual) && is_numeric($expected) && $actual <= $expected,
            'in' => is_array($expected) && in_array($actual, $expected, true),
            'not_in' => is_array($expected) && !in_array($actual, $expected, true),
            'contains' => is_array($actual)
                ? in_array($expected, $actual, true)
                : is_scalar($actual) && is_scalar($expected) && str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            'not_contains' => is_array($actual)
                ? !in_array($expected, $actual, true)
                : is_scalar($actual) && is_scalar($expected) && !str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            'starts_with' => is_scalar($actual) && is_scalar($expected) && str_starts_with(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            'ends_with' => is_scalar($actual) && is_scalar($expected) && str_ends_with(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            'between' => is_array($expected) && count($expected) === 2 && is_numeric($actual) && is_numeric($expected[0]) && is_numeric($expected[1]) && $actual >= $expected[0] && $actual <= $expected[1],
            'is_true' => $actual === true || $actual === 1 || $actual === '1',
            'is_false' => $actual === false || $actual === 0 || $actual === '0',
            default => false,
        };
    }
}
