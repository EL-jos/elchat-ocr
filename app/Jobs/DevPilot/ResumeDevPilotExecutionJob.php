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

/** Polls the exact already-approved proposal; the operation idempotency keys remain unchanged. */
final class ResumeDevPilotExecutionJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 0;
    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $taskId,
        public readonly string $proposalHash,
        public readonly int $pollSequence,
    ) {
        $this->onQueue(config('devpilot.apply_queue', 'devpilot-apply'));
    }

    public function uniqueId(): string
    {
        return $this->taskId.':resume:'.$this->proposalHash.':'.$this->pollSequence;
    }

    public function handle(DevPilotTaskRunner $runner): void
    {
        $runner->applyApproved($this->taskId, $this->proposalHash);
    }

    public function failed(?Throwable $exception): void
    {
        app(DevPilotTaskRunner::class)->fail($this->taskId);
    }
}
