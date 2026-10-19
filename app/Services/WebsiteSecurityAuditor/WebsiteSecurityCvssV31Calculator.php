<?php

namespace App\Services\WebsiteSecurityAuditor;

/** Calculates only the CVSS v3.1 Base Score from a complete, valid Base vector. */
final class WebsiteSecurityCvssV31Calculator
{
    private const ALLOWED = [
        'AV' => ['N', 'A', 'L', 'P'], 'AC' => ['L', 'H'], 'PR' => ['N', 'L', 'H'],
        'UI' => ['N', 'R'], 'S' => ['U', 'C'], 'C' => ['H', 'L', 'N'],
        'I' => ['H', 'L', 'N'], 'A' => ['H', 'L', 'N'],
        'E' => ['X', 'H', 'F', 'P', 'U'], 'RL' => ['X', 'U', 'W', 'T', 'O'], 'RC' => ['X', 'C', 'R', 'U'],
        'CR' => ['X', 'H', 'M', 'L'], 'IR' => ['X', 'H', 'M', 'L'], 'AR' => ['X', 'H', 'M', 'L'],
        'MAV' => ['X', 'N', 'A', 'L', 'P'], 'MAC' => ['X', 'L', 'H'], 'MPR' => ['X', 'N', 'L', 'H'],
        'MUI' => ['X', 'N', 'R'], 'MS' => ['X', 'U', 'C'],
        'MC' => ['X', 'H', 'L', 'N'], 'MI' => ['X', 'H', 'L', 'N'], 'MA' => ['X', 'H', 'L', 'N'],
    ];

    /** Returns null when the vector is invalid or is not CVSS 3.1. */
    public function baseScore(?string $vector): ?float
    {
        if (! is_string($vector) || ! str_starts_with($vector, 'CVSS:3.1/')) return null;
        $metrics = [];
        foreach (explode('/', substr($vector, 9)) as $segment) {
            $parts = explode(':', $segment, 2);
            if (count($parts) !== 2 || ! isset(self::ALLOWED[$parts[0]])
                || ! in_array($parts[1], self::ALLOWED[$parts[0]], true) || isset($metrics[$parts[0]])) return null;
            $metrics[$parts[0]] = $parts[1];
        }
        foreach (['AV', 'AC', 'PR', 'UI', 'S', 'C', 'I', 'A'] as $required) {
            if (! isset($metrics[$required])) return null;
        }

        $attackVector = ['N' => 0.85, 'A' => 0.62, 'L' => 0.55, 'P' => 0.20][$metrics['AV']];
        $attackComplexity = ['L' => 0.77, 'H' => 0.44][$metrics['AC']];
        $privilegesRequired = $metrics['S'] === 'U'
            ? ['N' => 0.85, 'L' => 0.62, 'H' => 0.27][$metrics['PR']]
            : ['N' => 0.85, 'L' => 0.68, 'H' => 0.50][$metrics['PR']];
        $userInteraction = ['N' => 0.85, 'R' => 0.62][$metrics['UI']];
        $confidentiality = ['H' => 0.56, 'L' => 0.22, 'N' => 0.0][$metrics['C']];
        $integrity = ['H' => 0.56, 'L' => 0.22, 'N' => 0.0][$metrics['I']];
        $availability = ['H' => 0.56, 'L' => 0.22, 'N' => 0.0][$metrics['A']];

        $iss = 1 - ((1 - $confidentiality) * (1 - $integrity) * (1 - $availability));
        $impact = $metrics['S'] === 'U'
            ? 6.42 * $iss
            : 7.52 * ($iss - 0.029) - 3.25 * (($iss - 0.02) ** 15);
        if ($impact <= 0) return 0.0;

        $exploitability = 8.22 * $attackVector * $attackComplexity * $privilegesRequired * $userInteraction;
        $base = $metrics['S'] === 'U'
            ? min($impact + $exploitability, 10)
            : min(1.08 * ($impact + $exploitability), 10);

        // FIRST Roundup means the smallest one-decimal value greater than or equal to the result.
        return ceil(($base * 10) - 1.0e-10) / 10;
    }
}
