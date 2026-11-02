<?php

namespace App\Services\DevPilot;

use App\Models\DevPilot\DevPilotTask;
use Illuminate\Support\Facades\DB;

final class DevPilotTaskMaintenanceService
{
    public function __construct(private readonly DevPilotTaskRunner $runner) {}

    public function expireProposals(): int
    {
        $expiredCount = 0;
        $hours = (int) config('devpilot.proposal_expiration_hours', 24);
        $tasks = DevPilotTask::query()->where('status', 'awaiting_approval')
            ->where(function ($query) use ($hours): void {
                $query->where('proposal_expires_at', '<=', now())
                    ->orWhere(function ($legacy) use ($hours): void {
                        $legacy->whereNull('proposal_expires_at')->where('created_at', '<=', now()->subHours($hours));
                    });
            })
            ->orderBy('created_at')->limit(500)->get(['id', 'site_id', 'project_id']);

        foreach ($tasks as $candidate) {
            $event = null;
            $lockedTask = null;
            DB::transaction(function () use ($candidate, $hours, &$event, &$lockedTask): void {
                $lockedTask = DevPilotTask::query()->where('site_id', $candidate->site_id)
                    ->where('project_id', $candidate->project_id)->lockForUpdate()->find($candidate->id);
                if (! $lockedTask || $lockedTask->status !== 'awaiting_approval') return;
                $expiresAt = $lockedTask->proposal_expires_at ?? $lockedTask->created_at?->copy()->addHours($hours);
                if (! $expiresAt || $expiresAt->isFuture()) return;

                $message = 'La proposition a expiré; aucune opération n’a été exécutée. Relancez la tâche pour obtenir un plan à jour.';
                $lockedTask->forceFill([
                    'status' => 'expired', 'proposal_expires_at' => null,
                    'proposed_changes_encrypted' => null, 'proposal_hash' => null,
                    'completed_at' => now(),
                    'result' => array_merge($lockedTask->result ?? [], ['summary' => $message]),
                ])->save();
                $event = $lockedTask->events()->create(['kind' => 'expired', 'message' => $message, 'created_at' => now()]);
            }, 5);

            if ($event && $lockedTask) {
                app(DevPilotRealtimeService::class)->publish($lockedTask, 'expired', (string) $event->message, [], $event->getKey());
                $expiredCount++;
            }
        }

        return $expiredCount;
    }

    /** CAS the stale row before asking the runner to enter its recovery path. */
    public function recoverStaleTasks(): int
    {
        $recovered = $this->recoverClaimedTasks();
        $cutoff = now()->subMinutes(max(90, (int) config('devpilot.watchdog_stale_minutes', 90)));
        $candidates = DevPilotTask::query()->whereIn('status', ['running', 'applying', 'executing'])
            ->where('updated_at', '<=', $cutoff)->orderBy('updated_at')->limit(200)->get();
        foreach ($candidates as $candidate) {
            $expectedStatus = (string) $candidate->status;
            $expectedUpdatedAt = (string) $candidate->getRawOriginal('updated_at');
            $claimedAt = now()->toDateTimeString();
            $claimed = DevPilotTask::query()->whereKey($candidate->id)
                ->where('status', $expectedStatus)->where('updated_at', $expectedUpdatedAt)
                ->update([
                    'status' => 'watchdog_recovering',
                    'error_code' => 'watchdog_stale_'.$expectedStatus,
                    'updated_at' => $claimedAt,
                ]);
            if ($claimed !== 1) continue;

            $this->runner->fail((string) $candidate->id, 'watchdog_recovering', $claimedAt);
            $recovered++;
        }

        return $recovered;
    }

    /** Finish a watchdog claim if a worker crashed between compare-and-swap and recovery. */
    private function recoverClaimedTasks(): int
    {
        $cutoff = now()->subSeconds(60);
        $claimed = DevPilotTask::query()->where('status', 'watchdog_recovering')
            ->whereIn('error_code', ['watchdog_stale_running', 'watchdog_stale_applying', 'watchdog_stale_executing'])
            ->where('updated_at', '<=', $cutoff)->orderBy('updated_at')->limit(200)->get();
        $recovered = 0;
        foreach ($claimed as $task) {
            $this->runner->fail((string) $task->id, 'watchdog_recovering', (string) $task->getRawOriginal('updated_at'));
            $recovered++;
        }

        return $recovered;
    }

    public function run(): void
    {
        $this->expireProposals();
        $this->recoverStaleTasks();
    }
}
