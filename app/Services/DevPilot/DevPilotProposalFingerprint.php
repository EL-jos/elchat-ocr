<?php

namespace App\Services\DevPilot;

use App\Models\DevPilot\DevPilotTask;

/** Binds the immutable proposal bytes to the exact tenant, task, target and approval context. */
final class DevPilotProposalFingerprint
{
    public static function forTask(DevPilotTask $task, array $proposal): string
    {
        $canonical = [
            'version' => 1,
            'site_id' => (string) $task->site_id,
            'project_id' => (string) $task->project_id,
            'task_id' => (string) $task->id,
            'agent_id' => (string) $task->agent_id,
            'connection_id' => (string) $task->connection_id,
            'target_snapshot_hash' => (string) $task->target_snapshot_hash,
            'instruction_sha256' => hash('sha256', (string) $task->instruction),
            'proposal' => $proposal,
        ];

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public static function confirmationPhrase(string $proposalHash): string
    {
        return 'CONFIRMER '.strtoupper(substr($proposalHash, 0, 8));
    }
}
