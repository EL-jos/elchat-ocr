<?php

namespace App\Jobs\VisitorIntelligence;
use romanzipp\QueueMonitor\Traits\IsMonitored;

use App\Models\VisitorIntelligenceAction;
use App\Services\VisitorIntelligence\VisitorIntelligenceActionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExecuteVisitorIntelligenceActionJob implements ShouldQueue
{
    use IsMonitored;
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120, 600];

    public function __construct(public readonly string $actionId)
    {
        $this->onQueue(config('proactive.queue', 'proactive'));
    }

    public function handle(VisitorIntelligenceActionService $actions): void
    {
        $action = VisitorIntelligenceAction::query()->find($this->actionId);
        if ($action) $actions->execute($action);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Visitor Intelligence action job failed.', [
            'action_id' => $this->actionId, 'error' => $exception?->getMessage(),
        ]);
    }
}
