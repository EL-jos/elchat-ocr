<?php

namespace App\Domain\Proactive;

use App\Models\Proactive\ProactiveCampaign;
use Carbon\CarbonImmutable;

class ProactiveScheduleService
{
    public function nextAllowedAt(ProactiveCampaign $campaign, CarbonImmutable $candidate): CarbonImmutable
    {
        $timezone = $campaign->timezone ?: 'UTC';
        $local = $candidate->setTimezone($timezone);
        $startsAt = $campaign->starts_at ? CarbonImmutable::instance($campaign->starts_at)->setTimezone($timezone) : null;

        if ($startsAt && $local->lt($startsAt)) {
            $local = $startsAt;
        }

        $allowedDays = $campaign->allowed_days ?: [1, 2, 3, 4, 5, 6, 7];
        $allowedDays = array_map(fn ($day) => is_numeric($day) ? (int) $day : $this->dayNumber((string) $day), $allowedDays);

        for ($day = 0; $day < 9; $day++) {
            if (!in_array($local->dayOfWeekIso, $allowedDays, true)) {
                $local = $local->addDay()->startOfDay();
                continue;
            }

            if (!$campaign->start_time || !$campaign->end_time) {
                return $local->utc();
            }

            $start = $local->setTimeFromTimeString((string) $campaign->start_time);
            $end = $local->setTimeFromTimeString((string) $campaign->end_time);

            if ($end->lte($start)) {
                $end = $end->addDay();
            }
            if ($local->lt($start)) {
                return $start->utc();
            }
            if ($local->lte($end)) {
                return $local->utc();
            }

            $local = $local->addDay()->startOfDay();
        }

        return $local->utc();
    }

    private function dayNumber(string $day): int
    {
        return ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7][strtolower(substr($day, 0, 3))] ?? 0;
    }
}
