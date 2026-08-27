<?php

namespace App\Jobs\social;
use romanzipp\QueueMonitor\Traits\IsMonitored;

use App\Models\Social\SocialEvent;
use App\Services\Social\Email\EmailEventParser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class GmailWebhookJob implements ShouldQueue
{
    use IsMonitored;
    use Dispatchable, InteractsWithQueue, Queueable;

    public int   $tries   = 5;
    public array $backoff = [10, 30, 60, 120, 300];

    public function __construct(public string $eventId) {}

    public function handle(EmailEventParser $parser): void
    {
        /**
         * @var SocialEvent $event
         */
        $event = SocialEvent::find($this->eventId);

        Log::info("DANS GMAIL WEBHOOK VENT", [
            'event' => $event->toArray()
        ]);

        if (!$event || $event->processing_status !== 'pending') return;

        $event->update(['processing_status' => 'processing']);

        try {
            $parser->handleGmail($event);
            $event->update(['processing_status' => 'processed']);
        } catch (Throwable $e) {
            report($e);
            $event->update(['processing_status' => 'failed']);
            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        $event = SocialEvent::find($this->eventId);
        $event?->update([
            'processing_status' => 'failed',
            'metadata'          => array_merge($event->metadata ?? [], [
                'last_error' => $exception->getMessage(),
                'failed_at'  => now()->toIso8601String(),
            ]),
        ]);
        Log::error('[Gmail] Job définitivement échoué', [
            'event_id' => $this->eventId,
            'error'    => $exception->getMessage(),
        ]);
    }
}
