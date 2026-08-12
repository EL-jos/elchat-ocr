<?php

namespace App\Domain\Email\Providers;

use App\Domain\Email\Contracts\EmailProviderInterface;
use App\Domain\Email\DTO\{EmailEvent, EmailSendResult, InboundEmailMessage, OutboundEmail};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Http, Log};

/**
 * Alternative gratuite à SES pour la phase de test — AUCUNE carte bancaire
 * requise à l'inscription (contrairement à AWS), contre 100 emails/mois en
 * essai. Aucun autre fichier de l'application ne change : c'est exactement
 * le sens de EmailProviderInterface. Passer à SES en production plus tard
 * = changer `EMAIL_PROVIDER=ses` dans .env, rien de plus.
 *
 * ⚠️ Modèle de sécurité DIFFÉRENT de SES : Postmark ne signe pas ses
 * webhooks par HMAC. Leur recommandation officielle est l'authentification
 * HTTP Basic intégrée à l'URL du webhook configurée dans leur dashboard
 * (https://user:pass@votre-domaine/webhook/...). Cette différence reste
 * entièrement encapsulée ici — EmailService ne voit qu'un booléen "signature
 * valide ou non", quel que soit le mécanisme réel derrière.
 *
 * Avantage notable : le webhook entrant Postmark envoie du JSON DÉJÀ PARSÉ
 * (TextBody, From, Subject, Headers...) — pas de MIME brut à décoder
 * soi-même comme avec SES.
 */
class PostmarkEmailProvider implements EmailProviderInterface
{
    public function __construct(
        private readonly string $serverToken,
        private readonly string $webhookUsername,
        private readonly string $webhookPassword,
    ) {
    }

    public function key(): string
    {
        return 'postmark';
    }

    // ── Envoi ────────────────────────────────────────────────────────

    public function send(OutboundEmail $email): EmailSendResult
    {
        try {
            $response = Http::withHeaders([
                'X-Postmark-Server-Token' => $this->serverToken,
                'Accept' => 'application/json',
            ])->post('https://api.postmarkapp.com/email', [
                'From' => "{$email->fromName} <{$email->from}>",
                'To' => $email->to,
                'Subject' => $email->subject,
                'TextBody' => $email->textBody,
                'Headers' => collect($email->headers)->map(fn ($v, $k) => ['Name' => $k, 'Value' => $v])->values()->all(),
                'MessageStream' => 'outbound',
            ]);
        } catch (\Throwable $e) {
            Log::error('PostmarkEmailProvider: envoi échoué', ['error' => $e->getMessage()]);
            return EmailSendResult::failed('postmark_request_error', $e->getMessage());
        }

        $body = $response->json();

        // ErrorCode 0 = accepté pour traitement — jamais "délivré" (même
        // logique que SES, voir EmailSendResult::accepted()).
        if (($body['ErrorCode'] ?? 1) !== 0) {
            Log::error('PostmarkEmailProvider: envoi refusé', ['body' => $body]);
            return EmailSendResult::failed((string) ($body['ErrorCode'] ?? 'unknown'), $body['Message'] ?? 'Erreur inconnue');
        }

        return EmailSendResult::accepted($body['MessageID']);
    }

    // ── Événements ───────────────────────────────────────────────────

    public function verifyEventWebhookSignature(Request $request): bool
    {
        return $this->verifyBasicAuth($request);
    }

    public function parseEventWebhook(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);
        $recordType = $payload['RecordType'] ?? null;

        $event = match ($recordType) {
            'Delivery' => 'delivered',
            'Bounce' => 'bounced',
            'SpamComplaint' => 'complained',
            'Open' => 'opened',
            'Click' => 'clicked',
            default => null, // 'Transient', 'Subscribe', 'Unsubscribe'... ignorés en V1
        };

        if (!$event || empty($payload['MessageID'])) {
            return [];
        }

        $subtype = null;
        if ($event === 'bounced') {
            // Postmark distingue précisément le type — HardBounce/SpamNotification
            // = définitif, le reste (SoftBounce, Transient...) = transitoire.
            $subtype = in_array($payload['Type'] ?? '', ['HardBounce', 'SpamNotification'], true) ? 'hard' : 'soft';
        }

        return [new EmailEvent(
            type: $event, providerMessageId: $payload['MessageID'],
            recipientEmail: $payload['Email'] ?? $payload['Recipient'] ?? null,
            occurredAt: new \DateTimeImmutable($payload['DeliveredAt'] ?? $payload['BouncedAt'] ?? $payload['ReceivedAt'] ?? 'now'),
            subtype: $subtype, raw: $payload,
        )];
    }

    /** Postmark n'a pas d'équivalent à la poignée de main SNS — rien à confirmer avant de recevoir de vrais événements. */
    public function handleWebhookHandshake(Request $request): ?array
    {
        return null;
    }

    // ── Réception ────────────────────────────────────────────────────

    public function verifyInboundWebhookSignature(Request $request): bool
    {
        return $this->verifyBasicAuth($request);
    }

    public function parseInboundWebhook(Request $request): ?InboundEmailMessage
    {
        $payload = json_decode($request->getContent(), true);
        if (empty($payload['TextBody']) && empty($payload['From'])) {
            return null;
        }

        $inReplyTo = collect($payload['Headers'] ?? [])->firstWhere('Name', 'In-Reply-To')['Value'] ?? null;

        return new InboundEmailMessage(
            from: $payload['From'] ?? '', to: $payload['To'] ?? '',
            subject: $payload['Subject'] ?? '', textBody: $payload['TextBody'] ?? '',
            providerMessageId: $payload['MessageID'] ?? null, inReplyTo: $inReplyTo,
        );
    }

    // ── Aides ────────────────────────────────────────────────────────

    /**
     * Modèle de sécurité officiel Postmark pour les webhooks : Basic Auth
     * sur l'URL configurée dans leur dashboard, jamais une signature
     * cryptographique du corps comme SNS. `hash_equals` pour éviter une
     * comparaison vulnérable au timing.
     */
    private function verifyBasicAuth(Request $request): bool
    {
        $user = $request->getUser();
        $pass = $request->getPassword();

        return $user !== null && $pass !== null
            && hash_equals($this->webhookUsername, $user)
            && hash_equals($this->webhookPassword, $pass);
    }
}
