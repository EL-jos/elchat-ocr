<?php

namespace App\Jobs\social;
use romanzipp\QueueMonitor\Traits\IsMonitored;

use App\Models\Social\SocialEvent;
use App\Services\Social\Facebook\FacebookEventParser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class FacebookWebhookJob implements ShouldQueue{
    use IsMonitored;
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $eventId
    ) {}

    public function handle(
        FacebookEventParser $parser
    ): void {

        $event = SocialEvent::find($this->eventId);

        Log::info("EVENT CONCERNE", [
            "eventId" => $this->eventId,
            "event" => $event->id
        ]);

        if (!$event || $event->processing_status !== 'pending') {
            return;
        }

        $event->update([
            'processing_status' => 'processing'
        ]);

        Log::info("EVENT CONCERNE 2", [
            "eventId" => $this->eventId,
            "event" => $event->id
        ]);

        try {

            $parser->handle(event: $event);

            $event->update([
                'processing_status' => 'processed'
            ]);

        } catch (Throwable $e) {

            report($e);

            $event->update([
                'processing_status' => 'failed'
            ]);
        }
    }
}
