<?php

namespace App\Services\WebsiteSecurityAuditor;

use App\Services\DashboardRealtimeService;

final class WebsiteSecurityAuditorRealtimeService
{
    public const TOPIC = 'website-security-auditor';

    public function __construct(private readonly DashboardRealtimeService $dashboardRealtime)
    {
    }

    /** @param array<string, mixed> $payload */
    public function publish(string $siteId, string $type, array $payload = []): void
    {
        $this->dashboardRealtime->publish(
            $siteId,
            $type,
            [
                'module' => 'website_security_auditor',
                ...$payload,
            ],
            [self::TOPIC],
        );
    }
}
