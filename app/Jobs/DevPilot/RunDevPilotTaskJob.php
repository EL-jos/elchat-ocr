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

final class RunDevPilotTaskJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    // A zero timeout lets a DevPilot task finish without a queue deadline.
    public int $timeout = 0;
    // A multi-cycle objective may legitimately run much longer than ten minutes.
    public int $uniqueFor = 86400;

    public function __construct(public readonly string $taskId)
    {
        $this->onQueue(config('devpilot.queue', 'devpilot'));
    }

    public function uniqueId(): string { return $this->taskId.':plan'; }

    public function handle(DevPilotTaskRunner $runner): void { $runner->run($this->taskId); }

    public function failed(?Throwable $exception): void { app(DevPilotTaskRunner::class)->fail($this->taskId); }
}
