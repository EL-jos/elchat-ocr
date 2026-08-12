<?php

namespace App\Domain\Email\Providers;

use App\Domain\Email\Contracts\EmailProviderInterface;
use App\Domain\Email\DTO\{EmailEvent, EmailSendResult, InboundEmailMessage, OutboundEmail};
use Aws\Ses\SesClient;
use Aws\Exception\AwsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, Http, Log};

/**
 * Toute la connaissance SES/SNS reste ICI, encapsulée — EmailService et
 * tout appelant en amont ne voient jamais un MessageId SES, un ARN de
 * topic SNS, ni le format de signature SNS.
 *
 * Nécessite le SDK AWS : `composer require aws/aws-sdk-php`
 *
 * Architecture attendue côté AWS (à documenter en déploiement, hors code) :
 * - Envoi : SES API `SendEmail` (config Set recommandé pour activer les événements)
 * - Événements (delivered/bounce/complaint/reject/open/click) : Configuration Set
 *   → Event Destination SNS → topic → abonnement HTTPS vers /webhook/email/ses/events
 * - Réception (réponses des prospects) : SES Receiving Rule (MX sur le domaine
 *   de prospection) → action SNS (contenu inclus) → topic → abonnement HTTPS
 *   vers /webhook/email/ses/inbound
 *
 * Les DEUX webhooks partagent la même enveloppe SNS et donc la même
 * vérification de signature — seul le contenu JSON du champ "Message" diffère.
 */
class SesEmailProvider implements EmailProviderInterface
{
    private const SIGNING_CERT_URL_PATTERN = '/^https:\/\/sns\.[a-zA-Z0-9\-]+\.amazonaws\.com\/.*\.pem$/';
    private const CERT_CACHE_TTL_SECONDS = 86400;

    public function __construct(private readonly SesClient $client)
    {
    }

    public function key(): string
    {
        return 'ses';
    }

    // ── Envoi ────────────────────────────────────────────────────────

    public function send(OutboundEmail $email): EmailSendResult
    {
        try {
            $result = $this->client->sendEmail([
                'FromEmailAddress' => "{$email->fromName} <{$email->from}>",
                'Destination' => ['ToAddresses' => [$email->to]],
                'Content' => [
                    'Simple' => [
                        'Subject' => ['Data' => $email->subject, 'Charset' => 'UTF-8'],
                        'Body' => ['Text' => ['Data' => $email->textBody, 'Charset' => 'UTF-8']],
                        'Headers' => collect($email->headers)->map(fn ($v, $k) => ['Name' => $k, 'Value' => $v])->values()->all(),
                    ],
                ],
            ]);
        } catch (AwsException $e) {
            Log::error('SesEmailProvider: envoi échoué', ['error' => $e->getAwsErrorMessage()]);
            return EmailSendResult::failed($e->getAwsErrorCode() ?? 'ses_error', $e->getAwsErrorMessage() ?? $e->getMessage());
        }

        // 'MessageId' ici signifie uniquement "SES a accepté la requête" —
        // jamais "délivré". La confirmation réelle arrive par événement webhook.
        return EmailSendResult::accepted($result['MessageId']);
    }

    // ── Événements ───────────────────────────────────────────────────

    public function verifyEventWebhookSignature(Request $request): bool
    {
        return $this->verifySnsEnvelope($request);
    }

    public function parseEventWebhook(Request $request): array
    {
        $envelope = json_decode($request->getContent(), true);
        if (($envelope['Type'] ?? null) !== 'Notification') {
            return []; // SubscriptionConfirmation gérée par handleWebhookHandshake, pas ici
        }

        $ses = json_decode($envelope['Message'] ?? '{}', true);
        $event = $this->mapSesEventType($ses['eventType'] ?? null);
        if (!$event) {
            return [];
        }

        $messageId = $ses['mail']['messageId'] ?? null;
        if (!$messageId) {
            return [];
        }

        [$recipient, $timestamp, $subtype] = $this->extractEventDetails($ses, $event);

        return [new EmailEvent(
            type: $event, providerMessageId: $messageId, recipientEmail: $recipient,
            occurredAt: $timestamp ? new \DateTimeImmutable($timestamp) : new \DateTimeImmutable(),
            subtype: $subtype, raw: $ses,
        )];
    }

    public function handleWebhookHandshake(Request $request): ?array
    {
        $envelope = json_decode($request->getContent(), true);
        if (($envelope['Type'] ?? null) !== 'SubscriptionConfirmation') {
            return null;
        }

        if (!$this->verifySnsEnvelope($request)) {
            Log::warning('SesEmailProvider: SubscriptionConfirmation avec signature invalide, ignorée.');
            return null;
        }

        // Confirmation d'abonnement SNS : visiter l'URL suffit à activer le topic.
        try {
            Http::get($envelope['SubscribeURL']);
            Log::info('SesEmailProvider: abonnement SNS confirmé.', ['topic' => $envelope['TopicArn'] ?? null]);
        } catch (\Throwable $e) {
            Log::error('SesEmailProvider: échec de confirmation SNS', ['error' => $e->getMessage()]);
        }

        return ['status' => 'subscription_confirmed'];
    }

    // ── Réception ────────────────────────────────────────────────────

    public function verifyInboundWebhookSignature(Request $request): bool
    {
        return $this->verifySnsEnvelope($request);
    }

    public function parseInboundWebhook(Request $request): ?InboundEmailMessage
    {
        $envelope = json_decode($request->getContent(), true);
        if (($envelope['Type'] ?? null) !== 'Notification') {
            return null;
        }

        $ses = json_decode($envelope['Message'] ?? '{}', true);
        if (($ses['notificationType'] ?? null) !== 'Received') {
            return null;
        }

        $headers = $ses['mail']['commonHeaders'] ?? [];
        $rawContent = $ses['content'] ?? null;

        return new InboundEmailMessage(
            from: $headers['from'][0] ?? ($ses['mail']['source'] ?? ''),
            to: $headers['to'][0] ?? '',
            subject: $headers['subject'] ?? '',
            textBody: $rawContent ? $this->extractPlainTextFromRawMime($rawContent) : '',
            providerMessageId: $headers['messageId'] ?? ($ses['mail']['messageId'] ?? null),
            inReplyTo: $headers['inReplyTo'] ?? null,
        );
    }

    // ── Aides — vérification de signature SNS (partagée events + inbound) ──

    private function verifySnsEnvelope(Request $request): bool
    {
        $envelope = json_decode($request->getContent(), true);
        if (!$envelope || empty($envelope['SigningCertURL']) || empty($envelope['Signature'])) {
            return false;
        }

        // Empêche un attaquant de fournir son propre certificat : le domaine
        // doit être un domaine SNS AWS légitime, jamais accepté "tel quel".
        if (!preg_match(self::SIGNING_CERT_URL_PATTERN, $envelope['SigningCertURL'])) {
            Log::warning('SesEmailProvider: SigningCertURL hors domaine AWS attendu, rejeté.', ['url' => $envelope['SigningCertURL']]);
            return false;
        }

        $publicKey = $this->fetchSigningCertPublicKey($envelope['SigningCertURL']);
        if (!$publicKey) {
            return false;
        }

        $stringToSign = $this->buildSnsStringToSign($envelope);
        $signature = base64_decode($envelope['Signature']);
        $algo = ($envelope['SignatureVersion'] ?? '1') === '2' ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1;

        return openssl_verify($stringToSign, $signature, $publicKey, $algo) === 1;
    }

    private function buildSnsStringToSign(array $envelope): string
    {
        $type = $envelope['Type'] ?? '';

        if (in_array($type, ['SubscriptionConfirmation', 'UnsubscribeConfirmation'], true)) {
            $fields = ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];
        } else {
            $fields = ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'];
        }

        $parts = '';
        foreach ($fields as $field) {
            if ($field === 'Subject' && !array_key_exists('Subject', $envelope)) {
                continue; // Subject omis du calcul si absent du message (cas Notification sans sujet)
            }
            if (!array_key_exists($field, $envelope)) {
                continue;
            }
            $parts .= "{$field}\n{$envelope[$field]}\n";
        }

        return $parts;
    }

    private function fetchSigningCertPublicKey(string $url): ?string
    {
        $pem = Cache::remember("sns_cert:" . md5($url), self::CERT_CACHE_TTL_SECONDS, function () use ($url) {
            $response = Http::timeout(5)->get($url);
            return $response->successful() ? $response->body() : null;
        });

        if (!$pem) {
            return null;
        }

        $key = openssl_pkey_get_public($pem);
        return $key ? $pem : null; // openssl_verify accepte directement le PEM
    }

    private function mapSesEventType(?string $sesEventType): ?string
    {
        return match ($sesEventType) {
            'Delivery' => 'delivered',
            'Bounce' => 'bounced',
            'Complaint' => 'complained',
            'Reject' => 'rejected',
            'Open' => 'opened',
            'Click' => 'clicked',
            default => null, // Send, RenderingFailure, DeliveryDelay... ignorés en V1
        };
    }

    /** @return array{0:?string,1:?string,2:?string} [recipient, timestamp, subtype] */
    private function extractEventDetails(array $ses, string $event): array
    {
        return match ($event) {
            'bounced' => [
                $ses['bounce']['bouncedRecipients'][0]['emailAddress'] ?? null,
                $ses['bounce']['timestamp'] ?? null,
                ($ses['bounce']['bounceType'] ?? null) === 'Permanent' ? 'hard' : 'soft',
            ],
            'complained' => [$ses['complaint']['complainedRecipients'][0]['emailAddress'] ?? null, $ses['complaint']['timestamp'] ?? null, null],
            'delivered' => [$ses['delivery']['recipients'][0] ?? null, $ses['delivery']['timestamp'] ?? null, null],
            'rejected' => [$ses['mail']['destination'][0] ?? null, null, null],
            'opened' => [$ses['mail']['destination'][0] ?? null, $ses['open']['timestamp'] ?? null, null],
            'clicked' => [$ses['mail']['destination'][0] ?? null, $ses['click']['timestamp'] ?? null, null],
            default => [null, null, null],
        };
    }

    /**
     * Extraction pragmatique du texte brut d'un email MIME — suffisant pour
     * V1 (privilégie text/plain, décode quoted-printable/base64 basique).
     * ⚠️ Pas un parseur MIME complet : à remplacer par une librairie dédiée
     * (ex: zbateson/mail-mime-parser) si des emails complexes posent problème.
     */
    private function extractPlainTextFromRawMime(string $raw): string
    {
        [$headerBlock, $body] = array_pad(preg_split("/\r?\n\r?\n/", $raw, 2), 2, '');

        if (preg_match('/boundary="?([^"\s;]+)"?/i', $headerBlock, $m)) {
            $boundary = $m[1];
            $parts = explode("--{$boundary}", $body);
            foreach ($parts as $part) {
                if (stripos($part, 'text/plain') !== false) {
                    [, $partBody] = array_pad(preg_split("/\r?\n\r?\n/", $part, 2), 2, ['', $part]);
                    return trim($this->decodeTransferEncoding($part, $partBody));
                }
            }
            return trim(strip_tags($body)); // repli : pas de partie text/plain trouvée
        }

        return trim($this->decodeTransferEncoding($headerBlock, $body));
    }

    private function decodeTransferEncoding(string $headers, string $body): string
    {
        if (stripos($headers, 'base64') !== false) {
            return base64_decode($body) ?: $body;
        }
        if (stripos($headers, 'quoted-printable') !== false) {
            return quoted_printable_decode($body);
        }
        return $body;
    }
}
