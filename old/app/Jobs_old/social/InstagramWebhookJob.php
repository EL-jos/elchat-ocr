<?php

namespace App\Jobs\social;

use App\Models\Social\SocialEvent;
use App\Services\Social\Instagram\InstagramEventParser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class InstagramWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Limite des tentatives globales (Laravel).
     * La logique custom "parent not ready" se limite à 5 retries
     * via $maxParentRetries, donc 10 laisse de la marge pour
     * d'autres erreurs transitoires (timeout réseau, etc.).
     */
    public int $tries = 10;

    public function __construct(
        public string $eventId
    ) {}

    public function handle(InstagramEventParser $parser): void
    {
        $event = SocialEvent::find($this->eventId);

        if (!$event) {
            Log::warning('[Instagram] SocialEvent introuvable', ['event_id' => $this->eventId]);
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

            // ✅ Relancer permet à Laravel de retenter (jusqu'à $tries)
            // en cas d'erreur transitoire (DB lock, timeout, etc.)
            throw $e;
        }
    }

    /**
     * Si toutes les tentatives échouent définitivement,
     * s'assurer que l'event reste marqué 'failed' pour
     * investigation manuelle/dashboard admin.
     */
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

        Log::error('[Instagram] Event définitivement échoué', [
            'event_id' => $this->eventId,
            'error'    => $exception->getMessage(),
        ]);
    }
}
