<?php

namespace App\Jobs\VisitorIntelligence;

use App\Models\VisitorSession;
use App\Services\VisitorIntelligence\VisitorIntelligenceBotScoringService;
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

    public function __construct(public readonly string $sessionId)
    {
        $this->onQueue(config('analytics.queue', 'analytics'));
    }

    public function uniqueId(): string
    {
        return $this->sessionId;
    }

    public function handle(VisitorIntelligenceBotScoringService $scoring): void
    {
        if (!config('visitor-intelligence.bot_detection.enabled', true)) return;

        $session = VisitorSession::query()->find($this->sessionId);
        if ($session) $scoring->scoreSession($session);
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Visitor Intelligence bot score job failed.', [
            'session_id' => $this->sessionId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
