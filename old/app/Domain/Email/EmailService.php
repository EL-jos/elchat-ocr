<?php

namespace App\Domain\Email;

use App\Domain\Email\Contracts\EmailProviderInterface;
use App\Domain\Email\DTO\{EmailEvent, EmailSendResult, InboundEmailMessage, OutboundEmail};
use Illuminate\Http\Request;

/**
 * SEUL point d'entrée pour tout le reste d'ELChat (Agent Sales Hunter,
 * campagnes, futurs autres agents). Ne connaît jamais le fournisseur
 * concret — reçoit le provider actif par injection (voir binding dans
 * AppServiceProvider, résolu depuis config('mail-providers.default')).
 *
 * Agent → EmailService → EmailProviderInterface → SES (ou autre)
 */
class EmailService
{
    public function __construct(private readonly EmailProviderInterface $provider)
    {
    }

    public function send(OutboundEmail $email): EmailSendResult
    {
        return $this->provider->send($email);
    }

    public function verifyEventWebhookSignature(Request $request): bool
    {
        return $this->provider->verifyEventWebhookSignature($request);
    }

    /** @return EmailEvent[] */
    public function parseEventWebhook(Request $request): array
    {
        return $this->provider->parseEventWebhook($request);
    }

    public function handleWebhookHandshake(Request $request): ?array
    {
        return $this->provider->handleWebhookHandshake($request);
    }

    public function verifyInboundWebhookSignature(Request $request): bool
    {
        return $this->provider->verifyInboundWebhookSignature($request);
    }

    public function parseInboundWebhook(Request $request): ?InboundEmailMessage
    {
        return $this->provider->parseInboundWebhook($request);
    }

    public function providerKey(): string
    {
        return $this->provider->key();
    }
}
