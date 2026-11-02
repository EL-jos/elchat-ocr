<?php

namespace App\Services\DevPilot;

use App\Models\DevPilot\DevPilotTask;
use App\Services\DashboardRealtimeService;

/** Publishes safe, tenant-scoped task activity without broadcasting inspected contents. */
final class DevPilotRealtimeService
{
    public function __construct(private readonly DashboardRealtimeService $realtime)
    {
    }

    public function publish(DevPilotTask $task, string $kind, string $message, array $details = [], string|int|null $activityId = null): void
    {
        $this->realtime->publish((string) $task->site_id, 'devpilot_task_update', [
            'module' => 'devpilot',
            'task_id' => (string) $task->id,
            'project_id' => (string) $task->project_id,
            'status' => (string) $task->status,
            'progress' => (int) $task->progress,
            'activity' => [
                'id' => $activityId === null ? null : (string) $activityId,
                'kind' => mb_strcut($kind, 0, 80),
                'message' => mb_strcut($message, 0, 500),
                'created_at' => now()->toISOString(),
                'details' => $this->safeDetails($details),
            ],
        ], ['devpilot']);
    }

    /** Keep only operational metadata; never forward search terms, file contents, or credentials. */
    private function safeDetails(array $details): array
    {
        $safe = [];
        if (isset($details['path']) && is_string($details['path'])) {
            $path = str_replace('\\', '/', trim($details['path']));
            $segments = explode('/', $path);
            $sensitive = preg_match('/(^|\/)(?:\.env(?:\.[^\/]*)?|\.ssh|\.aws|id_rsa|id_ed25519|authorized_keys)(\/|$)/i', $path) === 1;
            if ($path !== '' && ! str_starts_with($path, '/') && ! preg_match('/^[A-Za-z]:/', $path)
                && ! $sensitive && ! in_array('..', $segments, true)) {
                $safe['path'] = mb_strcut($path, 0, 300);
            }
        }

        foreach (['entries', 'matches', 'files_scanned', 'bytes', 'files_changed', 'total_files'] as $key) {
            if (isset($details[$key]) && is_numeric($details[$key])) {
                $safe[$key] = max(0, min(100000000, (int) $details[$key]));
            }
        }
        foreach (['truncated', 'cached'] as $key) {
            if (isset($details[$key]) && is_bool($details[$key])) $safe[$key] = $details[$key];
        }

        if (isset($details['terminal_session_id']) && is_string($details['terminal_session_id'])
            && preg_match('/^[a-f0-9-]{36}$/iD', $details['terminal_session_id']) === 1) {
            $safe['terminal_session_id'] = $details['terminal_session_id'];
        }
        if (isset($details['terminal_state']) && is_string($details['terminal_state'])
            && in_array($details['terminal_state'], ['starting', 'running', 'completed', 'failed', 'cancelled', 'uncertain'], true)) {
            $safe['terminal_state'] = $details['terminal_state'];
        }

        return $safe;
    }
}
