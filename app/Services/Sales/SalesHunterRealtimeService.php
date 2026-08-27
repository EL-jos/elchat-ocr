<?php

namespace App\Services\Sales;

use App\Services\DashboardRealtimeService;

/**
 * Publie les changements de Sales Hunter sans coupler les jobs à Mercure.
 * La publication reste best-effort via DashboardRealtimeService.
 */
class SalesHunterRealtimeService
{
    public const TOPIC = 'sales-hunter';

    public function __construct(private readonly DashboardRealtimeService $dashboardRealtime)
    {
    }

    public function publish(string $siteId, string $type, array $payload = []): void
    {
        $this->dashboardRealtime->publish(
            $siteId,
            $type,
            [
                'module' => 'sales_hunter',
                'refresh' => true,
                ...$payload,
            ],
            [self::TOPIC],
        );
    }
}
