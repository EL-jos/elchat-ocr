<?php

namespace App\SocialChannels\Email;

use App\Models\Social\SocialAccount;
use App\Models\Social\SocialMessage;
use App\SocialChannels\Contracts\SocialChannelInterface;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

class ImapChannel implements SocialChannelInterface
{
    public function connect(): void {}
    public function disconnect(): void {}
    public function fetchMessages(): array { return []; }
    public function refreshToken(SocialAccount $account): void {}
    public function getProvider(): string { return 'imap'; }

    public function sendReply(SocialAccount $account, SocialMessage $message): array
    {
        $metadata = $message->metadata ?? [];

        $toEmail   = $metadata['from_email']         ?? null;
        $toName    = $metadata['from_name']           ?? null;
        $subject   = 'Re: ' . ($metadata['subject']  ?? '');
        $inReplyTo = $metadata['in_reply_to']         ?? $metadata['message_id_header'] ?? null;
        $references = $metadata['message_id_header']  ?? null;

        if (!$toEmail) {
            throw new RuntimeException(
                "from_email manquant dans metadata du message {$message->id}."
            );
        }

        if (empty($message->content)) {
            throw new RuntimeException(
                "Contenu vide pour le message {$message->id}."
            );
        }

        $imap         = $account->metadata['imap']       ?? [];
        $fromEmail    = $account->metadata['email']      ?? $account->metadata['email_root'] ?? null;
        $fromName     = $account->account_name;
        $smtpPassword = isset($imap['password']) ? decrypt($imap['password']) : null;

        if (!$fromEmail || !$smtpPassword) {
            throw new RuntimeException(
                "Credentials SMTP manquants pour le compte {$account->id}."
            );
        }

        $smtpConfig = $account->metadata['smtp'] ?? null;

        if (!$smtpConfig || empty($smtpConfig['host']) || empty($smtpConfig['port'])) {
            throw new RuntimeException(
                "Configuration SMTP manquante pour le compte {$fromEmail}"
            );
        }

        try {
            $dsn = sprintf(
                'smtp://%s:%s@%s:%d',
                rawurlencode($fromEmail),
                rawurlencode($smtpPassword),
                $smtpConfig['host'],
                (int) $smtpConfig['port'],
            );

            $transport = Transport::fromDsn($dsn);
            $mailer    = new Mailer($transport);

            $email = (new Email())
                ->from(new Address($fromEmail, $fromName))
                ->to(new Address($toEmail, $toName ?? ''))
                ->subject($subject)
                ->text($message->content);

            // ✅ Headers de threading pour que la réponse s'affiche dans le bon fil
            if ($inReplyTo) {
                $email->getHeaders()->addTextHeader('In-Reply-To', $inReplyTo);
            }
            if ($references) {
                $email->getHeaders()->addTextHeader('References', $references);
            }

            $mailer->send($email);

            Log::info('[IMAP] Réponse envoyée via SMTP', [
                'account_id' => $account->id,
                'to'         => $toEmail,
                'subject'    => $subject,
            ]);

            return ['success' => true, 'to' => $toEmail, 'subject' => $subject];

        } catch (Throwable $e) {
            Log::error('[IMAP] Échec envoi SMTP', [
                'account_id' => $account->id,
                'to'         => $toEmail,
                'error'      => $e->getMessage(),
            ]);
            throw new RuntimeException("SMTP sendReply échoué: " . $e->getMessage());
        }
    }

    /**
     * Dériver l'hôte et le port SMTP depuis la config IMAP.
     * La plupart des providers utilisent le même hôte pour IMAP et SMTP.
     */
    private function resolveSmtpConfig(string $imapHost, int $imapPort, bool $ssl): array
    {
        // ✅ Remplacer 'imap.' par 'smtp.' pour les providers standards
        $smtpHost = preg_replace('/^imap\./i', 'smtp.', $imapHost);

        // Ports SMTP standards selon le chiffrement
        $smtpPort = match (true) {
            str_contains($imapHost, 'gmail.com')    => 587,
            str_contains($imapHost, 'one.com')      => 465,
            str_contains($imapHost, 'office365.com')=> 587,
            str_contains($imapHost, 'ovh.net')      => 587,
            str_contains($imapHost, 'infomaniak')   => 587,
            default                                  => $ssl ? 465 : 587,
        };

        return ['host' => $smtpHost, 'port' => $smtpPort];
    }
}
