<?php

namespace Tests\Unit\Proactive;

use App\Domain\Proactive\ProactiveScheduleService;
use App\Models\Proactive\ProactiveCampaign;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ProactiveScheduleServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_candidate_is_moved_to_the_next_allowed_business_day_and_hour(): void
    {
        $campaign = new ProactiveCampaign([
            'timezone' => 'Africa/Casablanca',
            'allowed_days' => [1, 2, 3, 4, 5],
            'start_time' => '09:00',
            'end_time' => '18:00',
        ]);

        $candidate = CarbonImmutable::parse('2026-08-15 20:00:00', 'Africa/Casablanca'); // samedi soir
        $next = (new ProactiveScheduleService())->nextAllowedAt($campaign, $candidate);

        $this->assertSame('2026-08-17 09:00:00', $next->setTimezone('Africa/Casablanca')->format('Y-m-d H:i:s'));
    }

    public function test_timezone_is_preserved_when_no_business_hours_are_configured(): void
    {
        $campaign = new ProactiveCampaign([
            'timezone' => 'Europe/Paris',
            'allowed_days' => [1, 2, 3, 4, 5, 6, 7],
        ]);

        $candidate = CarbonImmutable::parse('2026-08-15 10:00:00', 'UTC');
        $next = (new ProactiveScheduleService())->nextAllowedAt($campaign, $candidate);

        $this->assertSame('2026-08-15 12:00:00', $next->setTimezone('Europe/Paris')->format('Y-m-d H:i:s'));
    }
}
