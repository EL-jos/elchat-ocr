<?php

namespace App\Jobs;
use romanzipp\QueueMonitor\Traits\IsMonitored;

use App\Domain\Email\DTO\EmailEvent;
use App\Models\Sales\ProspectMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Met à jour le cycle de vie réel d'un message (delivered/bounced/
 * complained/rejected/opened/clicked) et bloque le prospect pour les
 * échecs définitifs — jamais pour un bounce transitoire (§ consigne :
 * ne pas confondre "accepté par l'API" et "délivré").
 */
class ProcessEmailEventJob implements ShouldQueue
{
    use IsMonitored;
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly EmailEvent $event)
    {
    }

    public function handle(): void
    {
        $message = ProspectMessage::with('prospect')
            ->where('provider_message_id', $this->event->providerMessageId)
            ->first();

        if (!$message) {
            return; // événement pour un message qu'on n'a pas émis (ou déjà nettoyé) — ignoré silencieusement
        }

        // 'opened'/'clicked' ne changent pas le statut du message (qui reste
        // 'delivered') — ce sont des signaux d'engagement additionnels, pas
        // un nouvel état du cycle de vie de la délivrabilité.
        if (in_array($this->event->type, ['opened', 'clicked'], true)) {
            $message->prospect?->touchActivity();
            return;
        }

        $message->update(['status' => $this->event->type]);

        if ($this->event->isPermanentFailure() && $message->prospect) {
            $message->prospect->update([
                'email_status' => $this->event->type === 'complained' ? 'complained' : 'bounced_hard',
            ]);
        } elseif ($this->event->type === 'bounced' && $this->event->subtype === 'soft' && $message->prospect) {
            $message->prospect->update(['email_status' => 'bounced_soft']); // informatif, ne bloque pas
        } elseif ($this->event->type === 'delivered' && $message->prospect) {
            $message->prospect->update(['email_status' => 'valid']);
        }

        $message->prospect?->touchActivity();
    }
}
