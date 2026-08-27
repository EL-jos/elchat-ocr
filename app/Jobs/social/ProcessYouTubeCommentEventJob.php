<?php

namespace App\Jobs\social;
use romanzipp\QueueMonitor\Traits\IsMonitored;

use App\Exceptions\YouTubeParentNotReadyException;
use App\Models\Social\SocialEvent;
use App\Services\Social\YoutTube\YouTubeEventParser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessYouTubeCommentEventJob implements ShouldQueue
{
    use IsMonitored;
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Nombre max de tentatives total (sécurité Laravel).
     * Notre logique custom limite déjà les retries "parent not ready"
     * à 5, donc 10 laisse de la marge pour d'autres erreurs transitoires.
     */
    public int $tries = 10;

    public function __construct(
        public string $eventId
    ) {}

    public function handle(YouTubeEventParser $parser): void
    {
        $event = SocialEvent::find($this->eventId);

        if (!$event) {
            Log::warning('[YouTube] SocialEvent introuvable', ['event_id' => $this->eventId]);
            return;
        }

        if ($event->processing_status !== 'pending') {
            return;
        }

        $event->update(['processing_status' => 'processing']);

        try {

            $parser->handle($event);

            $event->update(['processing_status' => 'processed']);

        } catch (YouTubeParentNotReadyException $e) {

            // ✅ Le commentaire racine n'est pas encore traité par un
            // autre worker. On remet l'event en 'pending' et on relance
            // ce job plus tard avec un backoff progressif.
            $event->update(['processing_status' => 'pending']);

            $maxParentRetries = 5;

            if ($this->attempts() < $maxParentRetries) {

                $delay = min(5 * $this->attempts(), 30); // 5s, 10s, 15s, 20s, 25s

                Log::info('[YouTube] Parent non prêt, retry programmé', [
                    'event_id' => $event->id,
                    'attempt'  => $this->attempts(),
                    'delay'    => $delay,
                ]);

                $this->release($delay);
                return;
            }

            // ✅ Après 5 tentatives, on abandonne la résolution du parent
            // mais on log une erreur claire pour investigation manuelle.
            Log::error('[YouTube] Parent introuvable après plusieurs tentatives', [
                'event_id' => $event->id,
                'message'  => $e->getMessage(),
            ]);

            $event->update(['processing_status' => 'failed']);

        } catch (Throwable $e) {

            report($e);

            $event->update(['processing_status' => 'failed']);
        }
    }
}
