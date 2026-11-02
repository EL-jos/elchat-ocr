<?php

namespace App\Services\DevPilot;

use App\Models\DevPilot\DevPilotTask;
use RuntimeException;

/** Builds a bounded encrypted before-image for the exact files and WordPress records a task will change. */
final class DevPilotRollbackSnapshot
{
    public const MAX_BYTES = 4194304;

    public static function append(?array $existing, DevPilotTask $task, array $changes, string $capturedAt, array $wordpressOperations = []): array
    {
        if ($existing !== null && (($existing['version'] ?? null) !== 1 || ! is_array($existing['snapshots'] ?? null))) {
            throw new RuntimeException('The retained recovery record is invalid; no additional file operation can start safely.');
        }
        $files = [];
        foreach ($changes as $change) {
            if (! is_array($change) || ! in_array($change['action'] ?? null, ['write', 'delete'], true)) continue;
            $previousContent = $change['previous_content'] ?? null;
            if ($previousContent !== null && ! is_string($previousContent)) {
                throw new RuntimeException('The pre-change content is invalid; no file operation can start safely.');
            }
            $files[] = [
                'path' => (string) ($change['path'] ?? ''),
                'action' => (string) $change['action'],
                'previous_exists' => $previousContent !== null,
                'previous_content' => $previousContent,
                'previous_sha256' => $change['expected_sha256'] ?? null,
                'expected_after_sha256' => ($change['action'] ?? null) === 'write' && is_string($change['content'] ?? null)
                    ? hash('sha256', $change['content']) : null,
            ];
        }
        $wordpress = [];
        foreach ($wordpressOperations as $operation) {
            if (! is_array($operation) || ! str_starts_with((string) ($operation['type'] ?? ''), 'wordpress_') || ! is_array($operation['before'] ?? null)) continue;
            $wordpress[] = [
                'type' => (string) ($operation['type'] ?? ''),
                'target' => array_intersect_key($operation, array_flip(['wordpress_type', 'id', 'setting_name', 'plugin_slug', 'plugin_action'])),
                'before' => $operation['before'],
                'before_sha256' => $operation['expected_record_sha256'] ?? null,
            ];
        }
        if ($files === [] && $wordpress === []) return $existing ?? ['version' => 1, 'snapshots' => []];

        $snapshots = is_array($existing['snapshots'] ?? null) ? $existing['snapshots'] : [];
        if (count($snapshots) >= 12) throw new RuntimeException('The task reached its retained recovery-snapshot limit; no additional file operation can start.');
        $snapshots[] = [
            'captured_at' => $capturedAt,
            'target_snapshot_hash' => (string) $task->target_snapshot_hash,
            'site_id' => (string) $task->site_id,
            'project_id' => (string) $task->project_id,
            'task_id' => (string) $task->id,
            'files' => $files,
            'wordpress' => $wordpress,
        ];
        $snapshot = ['version' => 1, 'snapshots' => $snapshots];
        $encoded = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > self::MAX_BYTES) throw new RuntimeException('The encrypted recovery record would exceed its storage limit; no file operation can start safely.');

        return $snapshot;
    }
}
