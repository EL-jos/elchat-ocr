<?php

namespace App\Jobs\DevPilot;

use App\Services\DevPilot\DevPilotTaskRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class ApplyDevPilotChangesJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    // A zero timeout lets an approved DevPilot change set finish without a queue deadline.
    public int $timeout = 0;
    // Keep the task lock longer than any single remote command or normal review cycle.
    public int $uniqueFor = 86400;

    public function __construct(
        public readonly string $taskId,
        public readonly string $proposalHash,
        public readonly bool $automaticCommandsOnly = false,
    )
    {
        $this->onQueue(config('devpilot.apply_queue', 'devpilot-apply'));
    }

    public function uniqueId(): string { return $this->taskId.':apply'; }

    public function handle(DevPilotTaskRunner $runner): void { $runner->applyApproved($this->taskId, $this->proposalHash, $this->automaticCommandsOnly); }

    public function failed(?Throwable $exception): void { app(DevPilotTaskRunner::class)->fail($this->taskId); }
}
