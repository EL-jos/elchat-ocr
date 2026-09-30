<?php

namespace App\Jobs\VisitorIntelligence;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ScoreVisitorSessionForBotActivityJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public array $backoff = [30, 120];
    public int $uniqueFor = 120;

    public function __construct(
        public readonly string $sessionId,
        public readonly bool $final = false,
    )
    {
        $this->onQueue(config('visitor-intelligence.queue', 'visitor-intelligence'));
    }

    public function uniqueId(): string
    {
        return $this->sessionId.($this->final ? ':final' : ':rolling');
    }

    /**
     * Automatic bot scoring was intentionally retired. Keep the class for
     * backwards-compatible deserialization of jobs queued by older releases,
     * but never execute a detection from the queue.
     */
    public function handle(): void
    {
        return;
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Visitor Intelligence bot score job failed.', [
            'session_id' => $this->sessionId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
