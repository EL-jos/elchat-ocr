<?php

namespace App\Domain\Sales\Email;

use App\Domain\Email\DTO\OutboundEmail;
use App\Domain\Email\EmailService;
use App\Models\Message;
use App\Models\Sales\Prospect;
use App\Models\Sales\ProspectMessage;
use Illuminate\Support\Str;

/**
 * Agent Sales Hunter → OutboundEmailSender → EmailService → EmailProviderInterface → SES
 *
 * Ne connaît QUE EmailService, jamais SES ni aucun fournisseur — c'est le
 * point exact demandé : "Agent → EmailService → EmailProviderInterface → SES,
 * et non Agent → SES directement."
 */
class OutboundEmailSender
{
    public function __construct(private readonly EmailService $emailService) {}

    public function send(Prospect $prospect, ProspectMessage $draft): void
    {
        $email = new OutboundEmail(
            to: $prospect->email,
            from: config('mail.from.address'),
            fromName: config('app.name'),
            subject: 'Message de '.config('app.name').' ['.$this->threadToken($prospect).']',
            textBody: $draft->content,
        );

        $result = $this->emailService->send($email);

        if (! $result->accepted) {
            $draft->update(['status' => 'failed']);

            return;
        }

        // 'accepted' UNIQUEMENT — jamais 'sent'/'delivered' à ce stade : voir
        // ProcessEmailEventJob pour la confirmation réelle par webhook.
        $draft->update([
            'status' => 'accepted',
            'provider_message_id' => $result->providerMessageId,
            'provider_key' => $this->emailService->providerKey(),
        ]);

        $message = Message::create([
            'id' => (string) Str::uuid(), 'conversation_id' => $prospect->conversation_id,
            'user_id' => null, 'role' => 'bot', 'content' => $draft->content,
        ]);
        $draft->update(['message_id' => $message->id]);

        $prospect->update(['status' => 'contacted']);
        $prospect->touchActivity();
    }

    private function threadToken(Prospect $prospect): string
    {
        return 'PID:'.substr($prospect->id, 0, 8);
    }
}
