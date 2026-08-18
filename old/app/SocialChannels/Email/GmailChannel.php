<?php

namespace App\SocialChannels\Email;

use App\Models\Social\SocialAccount;
use App\Models\Social\SocialMessage;
use App\SocialChannels\Contracts\SocialChannelInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GmailChannel implements SocialChannelInterface
{
    private string $baseUrl = 'https://gmail.googleapis.com/gmail/v1/users/me';

    public function connect(): void {}
    public function disconnect(): void {}
    public function fetchMessages(): array { return []; }
    public function getProvider(): string { return 'gmail'; }

    public function sendReply(SocialAccount $account, SocialMessage $message): array
    {
        $metadata = $message->metadata ?? [];

        $toEmail      = $this->extractEmail($metadata['from'] ?? '')
            ?? $metadata['from_email'] ?? null;
        $subject      = 'Re: ' . ($metadata['subject']          ?? '');
        $inReplyTo    = $metadata['message_id_header']           ?? null;
        $references   = $metadata['message_id_header']           ?? null;
        $threadId     = $metadata['thread_id']                   ?? null;
        $gmailMsgId   = $metadata['gmail_message_id']            ?? null;

        if (!$toEmail) {
            throw new RuntimeException(
                "Destinataire manquant dans metadata du message {$message->id}."
            );
        }

        if (empty($message->content)) {
            throw new RuntimeException(
                "Contenu vide pour le message {$message->id}."
            );
        }

        // ✅ Rafraîchir le token si nécessaire
        $this->refreshTokenIfNeeded($account);

        $fromEmail = $account->metadata['email'] ?? null;
        $fromName  = $account->account_name;

        // ✅ Construire le MIME brut pour Gmail API
        $rawEmail = $this->buildMimeEmail(
            from:       "{$fromName} <{$fromEmail}>",
            to:         $toEmail,
            subject:    $subject,
            body:       $message->content,
            inReplyTo:  $inReplyTo,
            references: $references,
        );

        $payload = ['raw' => rtrim(strtr(base64_encode($rawEmail), '+/', '-_'), '=')];

        // ✅ Attacher au thread Gmail pour que la réponse reste dans la conversation
        if ($threadId) {
            $payload['threadId'] = $threadId;
        }

        $response = Http::withToken($account->access_token)
            ->timeout(30)
            ->post("{$this->baseUrl}/messages/send", $payload);

        if ($response->status() === 401) {
            // Token expiré entre la vérification et l'appel — retry après refresh forcé
            $this->refreshToken($account);
            $response = Http::withToken($account->access_token)
                ->timeout(30)
                ->post("{$this->baseUrl}/messages/send", $payload);
        }

        if (!$response->successful()) {
            Log::error('[Gmail] Échec sendReply', [
                'account_id' => $account->id,
                'to'         => $toEmail,
                'status'     => $response->status(),
                'body'       => $response->body(),
            ]);
            throw new RuntimeException(
                "Gmail sendReply échoué ({$response->status()}): " . $response->body()
            );
        }

        Log::info('[Gmail] Réponse envoyée', [
            'account_id'   => $account->id,
            'to'           => $toEmail,
            'subject'      => $subject,
            'gmail_msg_id' => $response->json('id'),
        ]);

        return $response->json();
    }

    public function refreshToken(SocialAccount $account): void
    {
        if (!$account->refresh_token) {
            throw new RuntimeException(
                "Aucun refresh_token pour le compte Gmail {$account->id}."
            );
        }

        $response = Http::asForm()->timeout(15)->post(
            'https://oauth2.googleapis.com/token',
            [
                'client_id'     => config('services.gmail.client_id'),
                'client_secret' => config('services.gmail.client_secret'),
                'refresh_token' => $account->refresh_token,
                'grant_type'    => 'refresh_token',
            ]
        );

        if (!$response->successful()) {
            throw new RuntimeException(
                "Gmail token refresh échoué: " . $response->body()
            );
        }

        $data = $response->json();

        $account->update([
            'access_token'     => $data['access_token'],
            'token_expires_at' => now()->addSeconds($data['expires_in'] ?? 3600),
            'refresh_token'    => $data['refresh_token'] ?? $account->refresh_token,
        ]);

        Log::info('[Gmail] Token rafraîchi', ['account_id' => $account->id]);
    }

    // ─────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────

    private function refreshTokenIfNeeded(SocialAccount $account): void
    {
        $expiresAt = $account->token_expires_at;

        $needsRefresh = !$account->access_token
            || !$expiresAt
            || now()->greaterThanOrEqualTo(
                \Illuminate\Support\Carbon::parse($expiresAt)->subSeconds(60)
            );

        if ($needsRefresh) {
            $this->refreshToken($account);
        }
    }

    private function buildMimeEmail(
        string  $from,
        string  $to,
        string  $subject,
        string  $body,
        ?string $inReplyTo,
        ?string $references,
    ): string {
        $headers = [
            "From: {$from}",
            "To: {$to}",
            "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=",
            "MIME-Version: 1.0",
            "Content-Type: text/plain; charset=UTF-8",
            "Content-Transfer-Encoding: base64",
        ];

        if ($inReplyTo) {
            $headers[] = "In-Reply-To: {$inReplyTo}";
        }
        if ($references) {
            $headers[] = "References: {$references}";
        }

        return implode("\r\n", $headers) . "\r\n\r\n" . base64_encode($body);
    }

    private function extractEmail(string $raw): ?string
    {
        if (preg_match('/<([^>]+)>/', $raw, $m)) {
            return strtolower(trim($m[1]));
        }
        $clean = trim($raw);
        return filter_var($clean, FILTER_VALIDATE_EMAIL) ? strtolower($clean) : null;
    }
}
