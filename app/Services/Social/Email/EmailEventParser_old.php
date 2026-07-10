<?php

namespace App\Services\Social\Email;

use App\Enums\Social\MessageType;
use App\Jobs\social\SocialMessageReceivedJob;
use App\Models\Social\SocialAccount;
use App\Models\Social\SocialConversation;
use App\Models\Social\SocialEvent;
use App\Models\Social\SocialMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class EmailEventParser
{
    private string $gmailUrl  = 'https://gmail.googleapis.com/gmail/v1/users/me';
    private string $graphUrl  = 'https://graph.microsoft.com/v1.0';

    // ─────────────────────────────────────────────────────────
    // GMAIL
    // ─────────────────────────────────────────────────────────

    public function handleGmail(SocialEvent $event): void
    {
        $payload   = $event->payload;
        $historyId = $payload['history_id'] ?? null;

        $account = SocialAccount::find($event->social_account_id);

        if (!$account || !$account->is_active) {
            Log::warning('[Gmail][Parser] Compte introuvable', ['event_id' => $event->id]);
            return;
        }


        // ✅ Récupérer l'historique depuis le dernier historyId connu
        $startHistoryId = $account->sync_cursor ?? $historyId;

        Log::info("DANS EMAIL EVENT PARSER", [
            "payload" => $payload,
            "historyId" => $historyId,
            "accountId" => $account->id,
        ]);

        $history = $this->fetchGmailHistory($account, $startHistoryId);

        Log::info("HISTORY APRES FETCH GMAIL HISTORY", [
            "history" => $history,
        ]);

        if (empty($history)) {
            // Mettre à jour le curseur même si rien de nouveau
            $account->update(['sync_cursor' => $historyId]);
            return;
        }

        foreach ($history as $item) {
            foreach ($item['messagesAdded'] ?? [] as $added) {
                $messageId = $added['message']['id'] ?? null;
                if (!$messageId) continue;

                $this->processGmailMessage($account, $messageId);
            }
        }

        // ✅ Mettre à jour le curseur après traitement
        $account->update(['sync_cursor' => $historyId]);
    }

    private function fetchGmailHistory(SocialAccount $account, string $startHistoryId): array
    {
        try {
            $response = Http::withToken($account->access_token)
                ->get("{$this->gmailUrl}/history", [
                    'startHistoryId' => $startHistoryId,
                    'historyTypes'   => 'messageAdded',
                    'labelId'        => 'INBOX',
                ]);

            if ($response->status() === 404) {
                // historyId expiré — resync complet nécessaire
                Log::warning('[Gmail] HistoryId expiré, resync nécessaire', [
                    'account_id'     => $account->id,
                    'start_history_id' => $startHistoryId,
                ]);
                return [];
            }

            if (!$response->successful()) {
                throw new \RuntimeException(
                    "Gmail history fetch failed: " . $response->body()
                );
            }

            return $response->json('history', []);

        } catch (Throwable $e) {
            Log::error('[Gmail] fetchHistory error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function processGmailMessage(SocialAccount $account, string $gmailMessageId): void
    {
        // ✅ Déduplication
        $externalId = "gmail:{$account->id}:{$gmailMessageId}";

        if (SocialMessage::where('provider', 'gmail')
            ->where('external_message_id', $externalId)
            ->exists()) {
            return;
        }

        // ✅ Récupérer le message complet via Gmail API
        try {
            $response = Http::withToken($account->access_token)
                ->get("{$this->gmailUrl}/messages/{$gmailMessageId}", [
                    'format' => 'full',
                ]);

            if (!$response->successful()) return;

            $msg = $response->json();

        } catch (Throwable $e) {
            Log::error('[Gmail] fetchMessage error', ['error' => $e->getMessage()]);
            return;
        }

        $headers     = collect($msg['payload']['headers'] ?? []);
        $subject     = $headers->firstWhere('name', 'Subject')['value']    ?? '(no subject)';
        $from        = $headers->firstWhere('name', 'From')['value']       ?? null;
        $to          = $headers->firstWhere('name', 'To')['value']         ?? null;
        $date        = $headers->firstWhere('name', 'Date')['value']       ?? null;
        $messageIdH  = $headers->firstWhere('name', 'Message-ID')['value'] ?? null;
        $inReplyTo   = $headers->firstWhere('name', 'In-Reply-To')['value']?? null;
        $threadId    = $msg['threadId'] ?? null;

        $publishedAt = $date ? Carbon::parse($date) : now();
        $body        = $this->extractGmailBody($msg['payload'] ?? []);

        // ✅ Parser l'expéditeur (format "Nom <email@domain.com>")
        [$senderName, $senderEmail] = $this->parseEmailAddress($from ?? '');

        // ✅ Ignorer les emails envoyés par le compte lui-même (echo)
        $accountEmail = $account->metadata['email'] ?? null;
        if ($senderEmail && $accountEmail && strtolower($senderEmail) === strtolower($accountEmail)) {
            $this->storeEmailEcho($account, $externalId, $subject, $body, $threadId, $msg, $publishedAt);
            return;
        }

        $conversation = $this->resolveEmailConversation(
            account:     $account,
            senderId:    $senderEmail ?? $from ?? 'unknown',
            senderName:  $senderName,
            senderEmail: $senderEmail,
            threadId:    $threadId,
            subject:     $subject,
        );

        $message = SocialMessage::firstOrCreate(
            [
                'provider'            => 'gmail',
                'external_message_id' => $externalId,
            ],
            [
                'social_conversation_id' => $conversation->id,
                'direction'              => 'incoming',
                'content'                => $body ?? $subject,
                'message_type'           => MessageType::TEXT->value,
                'generated_by_ai'        => false,
                'metadata' => [
                    'gmail_message_id' => $gmailMessageId,
                    'thread_id'        => $threadId,
                    'subject'          => $subject,
                    'from'             => $from,
                    'to'               => $to,
                    'in_reply_to'      => $inReplyTo,
                    'message_id_header'=> $messageIdH,
                    'raw_headers'      => $headers->toArray(),
                ],
                'published_at' => $publishedAt,
            ]
        );

        if ($message->wasRecentlyCreated) {
            Log::info('[Gmail][Parser] Nouveau email entrant créé', [
                'message_id'      => $message->id,
                'conversation_id' => $conversation->id,
                'from'            => $from,
                'subject'         => $subject,
            ]);
            SocialMessageReceivedJob::dispatch($message->id);
        }

        $this->touchConversation($conversation, $publishedAt);
    }

    private function storeEmailEcho(
        SocialAccount $account,
        string        $externalId,
        string        $subject,
        ?string       $body,
        ?string       $threadId,
        array         $rawMsg,
        Carbon        $publishedAt,
    ): void {

        // ✅ Retrouver la conversation du thread
        $conversation = SocialConversation::where([
            'social_account_id' => $account->id,
            'provider'          => 'gmail',
            'context_id'        => $threadId,
        ])->latest('last_message_at')->first();

        if (!$conversation) return;

        SocialMessage::firstOrCreate(
            ['provider' => 'gmail', 'external_message_id' => $externalId],
            [
                'social_conversation_id' => $conversation->id,
                'direction'              => 'outgoing',
                'content'                => $body ?? $subject,
                'message_type'           => MessageType::TEXT->value,
                'generated_by_ai'        => true,
                'metadata'               => ['thread_id' => $threadId, 'subject' => $subject, 'is_echo' => true],
                'published_at'           => $publishedAt,
            ]
        );

        $this->touchConversation($conversation, $publishedAt);
    }

    // ─────────────────────────────────────────────────────────
    // OUTLOOK
    // ─────────────────────────────────────────────────────────

    public function handleOutlook(SocialEvent $event): void
    {
        $payload   = $event->payload;
        $messageId = $payload['message_id'] ?? null;

        $account = SocialAccount::find($event->social_account_id);

        if (!$account || !$account->is_active || !$messageId) return;

        $this->processOutlookMessage($account, $messageId);
    }

    private function processOutlookMessage(SocialAccount $account, string $outlookMessageId): void
    {
        $externalId = "outlook:{$account->id}:{$outlookMessageId}";

        if (SocialMessage::where('provider', 'outlook')
            ->where('external_message_id', $externalId)
            ->exists()) {
            return;
        }

        try {
            $response = Http::withToken($account->access_token)
                ->get("{$this->graphUrl}/me/messages/{$outlookMessageId}", [
                    '$select' => 'id,subject,from,toRecipients,body,receivedDateTime,conversationId,isRead,isDraft',
                ]);

            if (!$response->successful()) return;

            $msg = $response->json();

        } catch (Throwable $e) {
            Log::error('[Outlook] fetchMessage error', ['error' => $e->getMessage()]);
            return;
        }

        $subject      = $msg['subject']           ?? '(no subject)';
        $from         = $msg['from']['emailAddress'] ?? [];
        $senderEmail  = $from['address']           ?? null;
        $senderName   = $from['name']              ?? null;
        $threadId     = $msg['conversationId']     ?? null;
        $body         = strip_tags($msg['body']['content'] ?? '');
        $publishedAt  = isset($msg['receivedDateTime'])
            ? Carbon::parse($msg['receivedDateTime'])
            : now();

        $accountEmail = $account->metadata['email'] ?? null;

        if ($senderEmail && $accountEmail && strtolower($senderEmail) === strtolower($accountEmail)) {
            Log::info('[Outlook][Parser] Echo ignoré', ['message_id' => $outlookMessageId]);
            return;
        }

        $conversation = $this->resolveEmailConversation(
            account:     $account,
            senderId:    $senderEmail ?? 'unknown',
            senderName:  $senderName,
            senderEmail: $senderEmail,
            threadId:    $threadId,
            subject:     $subject,
        );

        $message = SocialMessage::firstOrCreate(
            ['provider' => 'outlook', 'external_message_id' => $externalId],
            [
                'social_conversation_id' => $conversation->id,
                'direction'              => 'incoming',
                'content'                => $body ?: $subject,
                'message_type'           => MessageType::TEXT->value,
                'generated_by_ai'        => false,
                'metadata' => [
                    'outlook_message_id' => $outlookMessageId,
                    'thread_id'          => $threadId,
                    'subject'            => $subject,
                    'from_email'         => $senderEmail,
                    'from_name'          => $senderName,
                    'is_read'            => $msg['isRead'] ?? false,
                    'raw'                => $msg,
                ],
                'published_at' => $publishedAt,
            ]
        );

        if ($message->wasRecentlyCreated) {
            Log::info('[Outlook][Parser] Nouveau email entrant créé', [
                'message_id'      => $message->id,
                'conversation_id' => $conversation->id,
                'from'            => $senderEmail,
                'subject'         => $subject,
            ]);
            SocialMessageReceivedJob::dispatch($message->id);
        }

        $this->touchConversation($conversation, $publishedAt);
    }

    // ─────────────────────────────────────────────────────────
    // IMAP (appelé par ImapSyncService)
    // ─────────────────────────────────────────────────────────

    public function handleImap(SocialAccount $account, array $emailData): void
    {
        $externalId = "imap:{$account->id}:{$emailData['uid']}";

        if (SocialMessage::where('provider', 'imap')
            ->where('external_message_id', $externalId)
            ->exists()) {
            return;
        }

        $subject     = $emailData['subject']      ?? '(no subject)';
        $senderEmail = $emailData['from_email']   ?? null;
        $senderName  = $emailData['from_name']    ?? null;
        $body        = $emailData['body']         ?? $subject;
        $threadId    = $emailData['message_id']   ?? null;
        $publishedAt = isset($emailData['date'])
            ? Carbon::parse($emailData['date'])
            : now();

        $accountEmail = $account->metadata['email'] ?? null;
        if ($senderEmail && $accountEmail && strtolower($senderEmail) === strtolower($accountEmail)) {
            return; // Echo ignoré pour IMAP
        }

        $conversation = $this->resolveEmailConversation(
            account:     $account,
            senderId:    $senderEmail ?? 'unknown',
            senderName:  $senderName,
            senderEmail: $senderEmail,
            threadId:    $threadId,
            subject:     $subject,
        );

        $message = SocialMessage::firstOrCreate(
            ['provider' => 'imap', 'external_message_id' => $externalId],
            [
                'social_conversation_id' => $conversation->id,
                'direction'              => 'incoming',
                'content'                => $body,
                'message_type'           => MessageType::TEXT->value,
                'generated_by_ai'        => false,
                'metadata'               => $emailData,
                'published_at'           => $publishedAt,
            ]
        );

        if ($message->wasRecentlyCreated) {
            SocialMessageReceivedJob::dispatch($message->id);
        }

        $this->touchConversation($conversation, $publishedAt);
    }

    // ─────────────────────────────────────────────────────────
    // RESOLVE CONVERSATION
    // Clé d'unicité email : (sender + thread/subject)
    // ─────────────────────────────────────────────────────────

    private function resolveEmailConversation(
        SocialAccount $account,
        string        $senderId,
        ?string       $senderName,
        ?string       $senderEmail,
        ?string       $threadId,
        string        $subject,
    ): SocialConversation {

        // ✅ threadId comme context_id si disponible,
        // sinon hash du subject (groupement par sujet)
        $contextId = $threadId
            ?? hash('sha256', strtolower(trim(preg_replace('/^(re|fwd?):\s*/i', '', $subject))));

        return SocialConversation::firstOrCreate(
            [
                'social_account_id' => $account->id,
                'provider'          => $account->provider,
                'external_user_id'  => $senderId,
                'context_type'      => 'email_thread',
                'context_id'        => $contextId,
            ],
            [
                'site_id'               => $account->site_id,
                'external_username'     => $senderEmail,
                'external_display_name' => $senderName ?? $senderEmail,
                'context_type'          => 'email_thread',
                'context_id'            => $contextId,
                'source_object_id'      => $contextId,
                'metadata' => [
                    'sender_email' => $senderEmail,
                    'sender_name'  => $senderName,
                    'subject'      => $subject,
                    'thread_id'    => $threadId,
                ],
                'last_message_at' => now(),
            ]
        );
    }

    // ─────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────

    private function extractGmailBody(array $payload): ?string
    {
        // ✅ Chercher text/plain en priorité, fallback text/html
        if (!empty($payload['body']['data'])) {
            return base64_decode(strtr($payload['body']['data'], '-_', '+/'));
        }

        foreach ($payload['parts'] ?? [] as $part) {
            if ($part['mimeType'] === 'text/plain' && !empty($part['body']['data'])) {
                return base64_decode(strtr($part['body']['data'], '-_', '+/'));
            }
        }

        foreach ($payload['parts'] ?? [] as $part) {
            if ($part['mimeType'] === 'text/html' && !empty($part['body']['data'])) {
                return strip_tags(base64_decode(strtr($part['body']['data'], '-_', '+/')));
            }
        }

        return null;
    }

    private function parseEmailAddress(string $raw): array
    {
        // ✅ Parser "Nom Prénom <email@domain.com>" ou "email@domain.com"
        if (preg_match('/^(.+?)\s*<([^>]+)>$/', trim($raw), $matches)) {
            return [trim($matches[1], '"\''), strtolower(trim($matches[2]))];
        }

        $email = strtolower(trim($raw));
        return [null, $email ?: null];
    }

    private function touchConversation(SocialConversation $conversation, Carbon $publishedAt): void
    {
        $current = $conversation->last_message_at
            ? Carbon::parse($conversation->last_message_at)
            : null;

        if (!$current || $publishedAt->greaterThan($current)) {
            $conversation->update(['last_message_at' => $publishedAt]);
        }
    }
}
