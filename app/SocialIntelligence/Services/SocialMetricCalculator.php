<?php

namespace App\SocialIntelligence\Services;

class SocialMetricCalculator
{
    public function change(?float $current, ?float $previous): ?float
    {
        if ($current === null || $previous === null) return null;
        if ($previous == 0.0) return $current == 0.0 ? 0.0 : null;
        return round((($current - $previous) / abs($previous)) * 100, 2);
    }

    public function trend(?float $change, int $currentSamples, int $previousSamples, int $minimumSamples = 3): string
    {
        if ($currentSamples < $minimumSamples || ($previousSamples > 0 && $previousSamples < $minimumSamples)) return 'insufficient_data';
        if ($change === null) return $currentSamples >= $minimumSamples ? 'emerging' : 'insufficient_data';
        if ($change >= 20) return 'growing';
        if ($change <= -20) return 'declining';
        return 'stable';
    }

    public function confidence(int $evidenceCount, bool $partial = false): string
    {
        if ($partial || $evidenceCount < 3) return 'low';
        if ($evidenceCount < 8) return 'medium';
        return 'high';
    }
}
