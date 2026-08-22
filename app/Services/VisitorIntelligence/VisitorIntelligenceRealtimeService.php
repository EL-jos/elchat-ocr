<?php

namespace App\Services\VisitorIntelligence;

use App\Services\DashboardRealtimeService;

class VisitorIntelligenceRealtimeService
{
    public const TOPIC = 'visitor-intelligence';

    public function __construct(private readonly DashboardRealtimeService $dashboardRealtime)
    {
    }

    public function publish(string $siteId, string $type, array $payload = []): void
    {
        $this->dashboardRealtime->publish(
            $siteId,
            $type,
            [
                'module' => 'visitor_intelligence',
                'refresh' => true,
                ...$payload,
            ],
            [self::TOPIC],
        );
    }
}
