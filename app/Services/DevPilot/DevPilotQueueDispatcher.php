<?php

namespace App\Services\DevPilot;

use App\Jobs\DevPilot\ApplyDevPilotChangesJob;
use App\Jobs\DevPilot\RunDevPilotTaskJob;
use App\Models\DevPilot\DevPilotProject;
use App\Models\DevPilot\DevPilotTask;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\DB;

final class DevPilotQueueDispatcher
{
    private const ACTIVE_EXECUTION_STATUSES = ['running', 'applying', 'executing', 'watchdog_recovering'];

    /** Re-dispatch one stale queued task per free project, always oldest first. */
    public function dispatchStaleTasks(): int
    {
        if (config('queue.default') === 'sync') return 0;

        $cutoff = now()->subSeconds(60);
        $projectIds = DevPilotTask::query()
            ->whereIn('status', ['queued', 'applying'])
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('created_at')
            ->limit(500)
            ->pluck('project_id')
            ->unique()
            ->values();

        $dispatched = 0;
        foreach ($projectIds as $projectId) {
            if ($this->dispatchNextForProject((string) $projectId, $cutoff)) $dispatched++;
        }

        return $dispatched;
    }

    private function dispatchNextForProject(string $projectId, $cutoff): bool
    {
        $dispatch = null;
        DB::transaction(function () use ($projectId, $cutoff, &$dispatch): void {
            $projectStub = DevPilotProject::query()->find($projectId);
            if (! $projectStub) return;
            $project = DevPilotProject::query()->where('site_id', $projectStub->site_id)
                ->lockForUpdate()->find($projectId);
            if (! $project) return;

            $task = DevPilotTask::query()->where('site_id', $project->site_id)
                ->where('project_id', $project->id)
                ->whereIn('status', ['queued', 'applying'])
                ->orderBy('created_at')->orderBy('id')
                ->lockForUpdate()->first();
            if (! $task || $task->updated_at?->gt($cutoff)) return;

            $busy = DevPilotTask::query()->where('project_id', $project->id)
                ->where('id', '!=', $task->id)
                ->whereIn('status', self::ACTIVE_EXECUTION_STATUSES)->exists();
            if ($busy) return;

            if ($task->status === 'queued') {
                $task->forceFill(['updated_at' => now()])->save();
                $dispatch = ['kind' => 'run', 'id' => (string) $task->id];
                return;
            }

            if (! is_string($task->proposal_hash) || $task->proposal_hash === '') return;
            $isApproved = $task->approved_at !== null && $task->approved_by !== null;
            $isAutomatic = $task->approved_at === null && $task->approved_by === null;
            if (! $isApproved && ! $isAutomatic) return;

            $task->forceFill(['updated_at' => now()])->save();
            $dispatch = ['kind' => 'apply', 'id' => (string) $task->id];
            $dispatch['proposal_hash'] = (string) $task->proposal_hash;
            $dispatch['automatic'] = $isAutomatic;
        }, 5);

        // Recovery dispatch deliberately bypasses ShouldBeUnique's stale lock. The project
        // row and task-state checks above are the authoritative execution deduplicators.
        if (($dispatch['kind'] ?? null) === 'run') {
            Queue::pushOn(config('devpilot.queue', 'devpilot'), new RunDevPilotTaskJob($dispatch['id']));
        } elseif (($dispatch['kind'] ?? null) === 'apply') {
            Queue::pushOn(config('devpilot.apply_queue', 'devpilot-apply'), new ApplyDevPilotChangesJob(
                $dispatch['id'], $dispatch['proposal_hash'], $dispatch['automatic'],
            ));
        }

        return $dispatch !== null;
    }
}
