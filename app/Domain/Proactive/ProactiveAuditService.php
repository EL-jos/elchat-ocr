<?php

namespace App\Domain\Proactive;

use App\Models\Proactive\ProactiveAuditLog;
use App\Services\DashboardRealtimeService;

class ProactiveAuditService
{
    public function __construct(private readonly DashboardRealtimeService $dashboardRealtime)
    {
    }

    public function record(string $action, array $context, ?string $reason = null, array $before = [], array $after = [], array $metadata = []): ProactiveAuditLog
    {
        $audit = ProactiveAuditLog::create([
            'account_id' => $context['account_id'],
            'site_id' => $context['site_id'],
            'campaign_id' => $context['campaign_id'] ?? null,
            'sequence_id' => $context['sequence_id'] ?? null,
            'message_id' => $context['message_id'] ?? null,
            'actor_id' => $context['actor_id'] ?? null,
            'actor_type' => $context['actor_type'] ?? 'system',
            'action' => $action,
            'reason' => $reason,
            'before_state' => $before ?: null,
            'after_state' => $after ?: null,
            'metadata' => $metadata ?: null,
            'created_at' => now(),
        ]);

        $this->dashboardRealtime->publish((string) $context['site_id'], 'proactive_update', [
            'audit_id' => (string) $audit->id,
            'action' => $action,
            'campaign_id' => $context['campaign_id'] ?? null,
            'sequence_id' => $context['sequence_id'] ?? null,
            'message_id' => $context['message_id'] ?? null,
        ]);

        return $audit;
    }
}
