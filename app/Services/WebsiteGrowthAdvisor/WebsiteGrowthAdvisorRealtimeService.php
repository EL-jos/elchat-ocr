<?php

namespace App\Services\WebsiteGrowthAdvisor;

use App\Services\DashboardRealtimeService;

final class WebsiteGrowthAdvisorRealtimeService
{
    public const TOPIC = 'website-growth-advisor';

    public function __construct(private readonly DashboardRealtimeService $dashboardRealtime)
    {
    }

    public function publish(string $siteId, string $type, array $payload = []): void
    {
        $this->dashboardRealtime->publish(
            $siteId,
            $type,
            [
                'module' => 'website_growth_advisor',
                ...$payload,
            ],
            [self::TOPIC],
        );
    }
}
