<?php

namespace App\Jobs\social;
use romanzipp\QueueMonitor\Traits\IsMonitored;

use App\Models\Social\SocialEvent;
use App\Services\Social\Telegram\TelegramEventParser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramWebhookJob implements ShouldQueue
{
    use IsMonitored;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public string $eventId
    ) {}

    public function handle(TelegramEventParser $parser): void
    {
        $event = SocialEvent::find($this->eventId);

        if (!$event) {
            Log::warning('[Telegram] SocialEvent introuvable', ['event_id' => $this->eventId]);
            return;
        }

        if ($event->processing_status !== 'pending') {
            return;
        }

        $event->update(['processing_status' => 'processing']);

        try {

            $parser->handle($event);

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
            'metadata' => array_merge($event->metadata ?? [], [
                'last_error' => $exception->getMessage(),
                'failed_at'  => now()->toIso8601String(),
            ]),
        ]);

        Log::error('[Telegram] Event définitivement échoué', [
            'event_id' => $this->eventId,
            'error'    => $exception->getMessage(),
        ]);
    }
}
