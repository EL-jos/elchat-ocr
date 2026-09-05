<?php

namespace App\Jobs;
use romanzipp\QueueMonitor\Traits\IsMonitored;

use App\Services\analytics\AnalyticsEventService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecordAnalyticsEventJob implements ShouldQueue
{
    use IsMonitored;
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [5, 30, 120, 300];

    public function __construct(private readonly array $payload)
    {
    }

    public function handle(AnalyticsEventService $analytics): void
    {
        $analytics->recordOrFail($this->payload);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Analytics event permanently failed after retries.', [
            'site_id' => $this->payload['site_id'] ?? null,
            'event_type' => $this->payload['event_type'] ?? null,
            'idempotency_key' => $this->payload['idempotency_key'] ?? null,
            'error' => $exception?->getMessage(),
        ]);
    }
}
