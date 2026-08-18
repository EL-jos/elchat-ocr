<?php

namespace App\Jobs\social;

use App\Models\Social\SocialAccount;
use App\Models\Social\SocialEvent;
use App\Services\Social\Email\EmailEventParser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class ImapProcessEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int   $tries   = 3;
    public array $backoff = [30, 60, 120];

    public function __construct(public string $eventId) {}

    public function handle(EmailEventParser $parser): void
    {
        $event = SocialEvent::find($this->eventId);

        if (!$event || $event->processing_status !== 'pending') return;

        $event->update(['processing_status' => 'processing']);

        $account = SocialAccount::find($event->social_account_id);

        if (!$account || !$account->is_active) {
            $event->update(['processing_status' => 'failed']);
            return;
        }

        try {

            $parser->handleImap($account, $event->payload);

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

        Log::error('[IMAP] ImapProcessEventJob définitivement échoué', [
            'event_id' => $this->eventId,
            'error'    => $exception->getMessage(),
        ]);
    }
}
