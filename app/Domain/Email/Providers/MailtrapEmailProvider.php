<?php

namespace App\Domain\Email\Providers;

use App\Domain\Email\Contracts\EmailProviderInterface;
use App\Domain\Email\DTO\{EmailEvent, EmailSendResult, InboundEmailMessage, OutboundEmail};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Http, Log};

/**
 * Alternative gratuite à SES/Postmark — aucune carte bancaire requise,
 * 4 000 emails/mois partagés envoi+réception. Volume nettement plus
 * confortable que Postmark (100/mois) pour tester une campagne sur
 * plusieurs jours. Aucun autre fichier de l'application ne change.
 *
 * Sécurité webhook : HMAC-SHA256 classique sur le corps brut, header
 * `Mailtrap-Signature` — plus simple que SNS (pas de certificat à
 * récupérer) et plus robuste que Postmark (pas juste une Basic Auth).
 *
 * ⚠️ Les noms de champs exacts du webhook d'ÉVÉNEMENTS (delivery/bounce/
 * complaint) ci-dessous sont ma meilleure estimation à partir des
 * conventions Mailtrap documentées, mais je n'ai pas vu un payload réel
 * de ce webhook précis — À VÉRIFIER contre un événement effectivement
 * reçu en test avant de s'y fier en production (voir TODO dans
 * parseEventWebhook). Le webhook ENTRANT en revanche est basé sur un
 * exemple de payload réel documenté par Mailtrap.
 */
class MailtrapEmailProvider implements EmailProviderInterface
{
    public function __construct(
        private readonly string $apiToken,
        private readonly string $webhookSigningSecret,
    ) {
    }

    public function key(): string
    {
        return 'mailtrap';
    }

    // ── Envoi ────────────────────────────────────────────────────────

    public function send(OutboundEmail $email): EmailSendResult
    {
        try {
            $response = Http::withToken($this->apiToken)->post('https://send.api.mailtrap.io/api/send', [
                'from' => ['email' => $email->from, 'name' => $email->fromName],
                'to' => [['email' => $email->to]],
                'subject' => $email->subject,
                'text' => $email->textBody,
                'headers' => $email->headers,
            ]);
        } catch (\Throwable $e) {
            Log::error('MailtrapEmailProvider: envoi échoué', ['error' => $e->getMessage()]);
            return EmailSendResult::failed('mailtrap_request_error', $e->getMessage());
        }

        $body = $response->json();

        if (!$response->successful() || empty($body['success'])) {
            Log::error('MailtrapEmailProvider: envoi refusé', ['body' => $body]);
            return EmailSendResult::failed('mailtrap_send_error', json_encode($body['errors'] ?? $body));
        }

        // 'accepted' UNIQUEMENT — jamais 'delivered' à ce stade.
        return EmailSendResult::accepted($body['message_ids'][0] ?? $body['message_id'] ?? '');
    }

    // ── Événements ───────────────────────────────────────────────────

    public function verifyEventWebhookSignature(Request $request): bool
    {
        return $this->verifyHmacSignature($request);
    }

    public function parseEventWebhook(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);
        // Certains comptes reçoivent un batch JSONL (une ligne = un événement) —
        // on normalise toujours vers un tableau d'événements.
        $rows = isset($payload['events']) ? $payload['events'] : [$payload];

        $events = [];
        foreach ($rows as $row) {
            // TODO : noms de champs à confirmer contre un vrai payload reçu.
            $type = match ($row['event'] ?? null) {
                'delivery' => 'delivered',
                'bounce' => 'bounced',
                'spam_complaint' => 'complained',
                'reject' => 'rejected',
                'open' => 'opened',
                'click' => 'clicked',
                default => null,
            };

            if (!$type || empty($row['message_id'])) {
                continue;
            }

            $events[] = new EmailEvent(
                type: $type, providerMessageId: $row['message_id'], recipientEmail: $row['email'] ?? null,
                occurredAt: isset($row['timestamp']) ? (new \DateTimeImmutable())->setTimestamp((int) $row['timestamp']) : new \DateTimeImmutable(),
                subtype: $type === 'bounced' ? (($row['bounce_category'] ?? null) === 'hard' ? 'hard' : 'soft') : null,
                raw: $row,
            );
        }

        return $events;
    }

    /** Pas de poignée de main particulière côté Mailtrap. */
    public function handleWebhookHandshake(Request $request): ?array
    {
        return null;
    }

    // ── Réception ────────────────────────────────────────────────────

    public function verifyInboundWebhookSignature(Request $request): bool
    {
        return $this->verifyHmacSignature($request);
    }

    public function parseInboundWebhook(Request $request): ?InboundEmailMessage
    {
        $payload = json_decode($request->getContent(), true);

        // Structure confirmée par la documentation Mailtrap "Agent Inbox" :
        // {from: {name, email}, to: [{email}], subject, text, ...}
        if (empty($payload['from']['email']) && empty($payload['text'])) {
            return null;
        }

        $inReplyTo = collect($payload['headers'] ?? [])->firstWhere('name', 'In-Reply-To')['value'] ?? null;

        return new InboundEmailMessage(
            from: $payload['from']['email'] ?? '',
            to: $payload['to'][0]['email'] ?? '',
            subject: $payload['subject'] ?? '',
            textBody: $payload['text'] ?? '',
            providerMessageId: $payload['id'] ?? null,
            inReplyTo: $inReplyTo,
        );
    }

    // ── Aides ────────────────────────────────────────────────────────

    private function verifyHmacSignature(Request $request): bool
    {
        $signature = $request->header('Mailtrap-Signature');
        if (!$signature) {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $this->webhookSigningSecret);

        return hash_equals($expected, $signature);
    }
}
