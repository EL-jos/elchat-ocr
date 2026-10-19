<?php

namespace App\Services\WebsiteSecurityAuditor;

/** Keeps technical observations separate from risk interpretation. */
final class WebsiteSecurityRiskEngine
{
    /** @param array<int, string> $factors @return array{level: string, reasons: array<int, string>, model: string} */
    public function assess(string $severity, array $factors): array
    {
        $level = in_array($severity, ['critical', 'high', 'medium', 'low', 'informational'], true)
            ? $severity
            : 'unknown';

        return [
            'level' => $level,
            'reasons' => array_values(array_unique($factors)),
            'model' => 'rule_based_observation_v1',
        ];
    }
}
