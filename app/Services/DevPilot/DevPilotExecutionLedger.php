<?php

namespace App\Services\DevPilot;

use App\Domain\MCP\Security\CredentialVault;
use App\Models\DevPilot\DevPilotTask;
use App\Models\DevPilot\DevPilotTaskActionRun;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Durable, tool-agnostic execution journal for one exact approved proposal. */
final class DevPilotExecutionLedger
{
    public function __construct(private readonly CredentialVault $vault) {}

    /** @return list<array{index:int,fingerprint:string,type:string,replay_safe:bool}> */
    public function prepare(DevPilotTask $task, string $proposalHash, array $changes, array $operations): array
    {
        $actions = [];
        foreach (array_values($changes) as $change) $actions[] = ['kind' => 'file', 'value' => $change];
        foreach (array_values($operations) as $operation) $actions[] = ['kind' => 'operation', 'value' => $operation];

        $prepared = [];
        foreach ($actions as $index => $action) {
            $value = is_array($action['value']) ? $action['value'] : [];
            $type = $action['kind'] === 'file' ? 'file:'.(string) ($value['action'] ?? 'unknown') : (string) ($value['type'] ?? 'unknown');
            $fingerprint = hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
            $replaySafe = $action['kind'] === 'operation'
                && str_starts_with($type, 'wordpress_')
                && ($value['channel'] ?? null) === 'bridge'
                && self::isUuid((string) ($value['idempotency_key'] ?? ''));

            DevPilotTaskActionRun::query()->firstOrCreate(
                ['task_id' => $task->id, 'proposal_hash' => $proposalHash, 'action_index' => $index],
                [
                    'action_fingerprint' => $fingerprint, 'action_type' => mb_strcut($type, 0, 96),
                    'status' => 'pending', 'replay_safe' => $replaySafe,
                ],
            );

            $row = DevPilotTaskActionRun::query()->where('task_id', $task->id)
                ->where('proposal_hash', $proposalHash)->where('action_index', $index)->first();
            if ($row && hash_equals((string) $row->action_fingerprint, $fingerprint)) {
                $prepared[] = ['index' => $index, 'fingerprint' => $fingerprint, 'type' => $type, 'replay_safe' => $replaySafe];
            }
        }

        return $prepared;
    }

    /**
     * Atomically claim one action. Completed actions return their encrypted
     * result so a restarted worker can report them without applying them again.
     *
     * @return array{execute:bool,status:string,result:?array}
     */
    public function begin(DevPilotTask $task, string $proposalHash, int $index, bool $replaySafe): array
    {
        return DB::transaction(function () use ($task, $proposalHash, $index, $replaySafe): array {
            $row = DevPilotTaskActionRun::query()->where('task_id', $task->id)->where('proposal_hash', $proposalHash)
                ->where('action_index', $index)->lockForUpdate()->first();
            if (! $row) return ['execute' => false, 'status' => 'missing', 'result' => null];
            if ($row->status === 'succeeded') return ['execute' => false, 'status' => 'succeeded', 'result' => $this->openResult($row->result_encrypted)];
            if (in_array($row->status, ['running', 'uncertain', 'failed'], true) && ! ($row->replay_safe && $replaySafe)) {
                return ['execute' => false, 'status' => (string) $row->status, 'result' => null];
            }
            $row->forceFill([
                'status' => 'running', 'replay_safe' => $replaySafe,
                'attempts' => min(65535, (int) $row->attempts + 1), 'started_at' => now(),
                'completed_at' => null, 'error_code' => null,
            ])->save();
            return ['execute' => true, 'status' => 'running', 'result' => null];
        }, 3);
    }

    public function finish(DevPilotTask $task, string $proposalHash, int $index, string $status, ?array $result = null, ?string $errorCode = null): void
    {
        if (! in_array($status, ['succeeded', 'failed', 'uncertain', 'skipped'], true)) return;
        $encrypted = null;
        if ($result !== null) {
            try {
                $encrypted = $this->vault->seal($result);
            } catch (Throwable) {
                // Status must still be durable if a bulky output cannot be encrypted.
            }
        }
        DevPilotTaskActionRun::query()->where('task_id', $task->id)->where('proposal_hash', $proposalHash)
            ->where('action_index', $index)->update([
                'status' => $status, 'result_encrypted' => $encrypted, 'error_code' => $errorCode,
                'completed_at' => now(), 'updated_at' => now(),
            ]);
    }

    /** Mark only claimed actions uncertain after a worker interruption. */
    public function markInterrupted(DevPilotTask $task, ?string $proposalHash): void
    {
        if (! is_string($proposalHash) || preg_match('/^[a-f0-9]{64}$/D', $proposalHash) !== 1) return;
        DevPilotTaskActionRun::query()->where('task_id', $task->id)->where('proposal_hash', $proposalHash)
            ->where('status', 'running')->update([
                'status' => 'uncertain', 'error_code' => 'worker_interrupted', 'updated_at' => now(),
            ]);
    }

    /** Return the durable, ordered state of every action in one approved proposal. */
    public function outcomes(DevPilotTask $task, string $proposalHash): array
    {
        return DevPilotTaskActionRun::query()->where('task_id', $task->id)->where('proposal_hash', $proposalHash)
            ->orderBy('action_index')->get()->map(function (DevPilotTaskActionRun $row): array {
                return [
                    'index' => (int) $row->action_index,
                    'type' => (string) $row->action_type,
                    'status' => (string) $row->status,
                    'attempts' => (int) $row->attempts,
                    'error_code' => is_string($row->error_code) ? $row->error_code : null,
                    'result' => $this->openResult($row->result_encrypted),
                ];
            })->all();
    }

    /**
     * Resume the same approved scope only when every in-flight/uncertain action
     * has a stable remote idempotency key. Unattempted actions remain authorized.
     */
    public function canResumeApproved(DevPilotTask $task): bool
    {
        if (! is_string($task->proposal_hash) || ! is_string($task->proposed_changes_encrypted)
            || ! $task->approved_at || ! $task->approved_by
            || ($task->plan['recovery_requires_state_check'] ?? false) !== true
            || ($task->plan['target_changed_after_user_pause'] ?? false) === true) return false;

        $rows = DevPilotTaskActionRun::query()->where('task_id', $task->id)->where('proposal_hash', $task->proposal_hash)->get();
        if ($rows->isEmpty()) return false;
        $hasRecoverableAction = false;
        foreach ($rows as $row) {
            $storedResult = $this->openResult($row->result_encrypted);
            if (($storedResult['items_uncertain'] ?? false) === true) return false;
            if ($row->status === 'succeeded') {
                continue;
            }
            if (in_array($row->status, ['pending', 'skipped'], true)) continue;
            if ($row->error_code === 'remote_result_uncertain_requires_inspection') return false;
            if (! $row->replay_safe || ! in_array($row->status, ['running', 'uncertain', 'failed'], true)) return false;
            $hasRecoverableAction = true;
        }
        return $hasRecoverableAction;
    }

    private function openResult(?string $encrypted): ?array
    {
        if (! is_string($encrypted) || $encrypted === '') return null;
        try {
            $result = $this->vault->open($encrypted);
            return is_array($result) ? $result : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $value) === 1;
    }
}
