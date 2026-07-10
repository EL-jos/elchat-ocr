<?php

namespace App\Services\Social\Email;

use App\Enums\Social\MessageType;
use App\Jobs\social\SocialMessageReceivedJob;
use App\Models\Social\SocialAccount;
use App\Models\Social\SocialConversation;
use App\Models\Social\SocialEvent;
use App\Models\Social\SocialMessage;
use App\Services\Social\ConversationBridgeService;
use App\Services\Social\UserResolver;
use App\SocialChannels\Email\GmailChannel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class EmailEventParser
{
    private string $gmailUrl = 'https://gmail.googleapis.com/gmail/v1/users/me';
    private string $graphUrl = 'https://graph.microsoft.com/v1.0';

    public function __construct(
        private readonly UserResolver              $userResolver,
        private readonly ConversationBridgeService $conversationBridge,
    ) {}

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

        // ✅ FIX — sync_cursor - 1 pour inclure le message courant
        // Gmail history.list est EXCLUSIF sur startHistoryId :
        // startHistoryId=X → retourne uniquement les events avec historyId > X
        $startHistoryId = $account->sync_cursor
            ? ((int) $account->sync_cursor - 1)
            : ((int) $historyId - 1);

        $history = $this->fetchGmailHistory($account, (string) $startHistoryId);

        Log::info('[Gmail] History fetched', [
            'start_history_id' => $startHistoryId,
            'count'            => count($history),
        ]);

        if (empty($history)) {
            if ($historyId) {
                $account->update(['sync_cursor' => (string) $historyId]);
            }
            return;
        }

        foreach ($history as $item) {
            foreach ($item['messagesAdded'] ?? [] as $added) {
                $messageId = $added['message']['id'] ?? null;
                if (!$messageId) continue;

                $labels = $added['message']['labelIds'] ?? [];
                if (in_array('SENT', $labels)) {
                    Log::info('[Gmail] Message SENT ignoré', ['message_id' => $messageId]);
                    continue;
                }

                $this->processGmailMessage($account, $messageId);
            }
        }

        if ($historyId) {
            $account->update(['sync_cursor' => (string) $historyId]);
        }
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

            Log::info('[Gmail] History API response', [
                'account_id'       => $account->id,
                'start_history_id' => $startHistoryId,
                'status'           => $response->status(),
                'items_count'      => count($response->json('history', [])),
            ]);

            if ($response->status() === 404) {
                Log::warning('[Gmail] HistoryId expiré (404), re-register Watch', [
                    'account_id'       => $account->id,
                    'start_history_id' => $startHistoryId,
                ]);
                app(GmailWatchService::class)->renew($account);
                return [];
            }

            if ($response->status() === 401) {
                Log::warning('[Gmail] Token expiré (401), refresh nécessaire', [
                    'account_id' => $account->id,
                ]);
                app(GmailChannel::class)->refreshToken($account);

                $response = Http::withToken($account->fresh()->access_token)
                    ->get("{$this->gmailUrl}/history", [
                        'startHistoryId' => $startHistoryId,
                        'historyTypes'   => 'messageAdded',
                        'labelId'        => 'INBOX',
                    ]);
            }

            if (!$response->successful()) {
                throw new \RuntimeException(
                    "Gmail history fetch failed ({$response->status()}): " . $response->body()
                );
            }

            return $response->json('history', []);

        } catch (Throwable $e) {
            Log::error('[Gmail] fetchHistory error', [
                'account_id' => $account->id,
                'error'      => $e->getMessage(),
            ]);
            return [];
        }
    }

    private function processGmailMessage(SocialAccount $account, string $gmailMessageId): void
    {
        $externalId = "gmail:{$account->id}:{$gmailMessageId}";

        if (SocialMessage::where('provider', 'gmail')
            ->where('external_message_id', $externalId)
            ->exists()) {
            return;
        }

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

        $headers    = collect($msg['payload']['headers'] ?? []);
        $subject    = $headers->firstWhere('name', 'Subject')['value']    ?? '(no subject)';
        $from       = $headers->firstWhere('name', 'From')['value']       ?? null;
        $to         = $headers->firstWhere('name', 'To')['value']         ?? null;
        $date       = $headers->firstWhere('name', 'Date')['value']       ?? null;
        $messageIdH = $headers->firstWhere('name', 'Message-ID')['value'] ?? null;
        $inReplyTo  = $headers->firstWhere('name', 'In-Reply-To')['value'] ?? null;
        $threadId   = $msg['threadId'] ?? null;

        $publishedAt = $date ? Carbon::parse($date) : now();
        $body        = $this->extractGmailBody($msg['payload'] ?? []);

        [$senderName, $senderEmail] = $this->parseEmailAddress($from ?? '');

        // ✅ Echo = email envoyé par le compte lui-même
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
                'metadata'               => [
                    'gmail_message_id'  => $gmailMessageId,
                    'thread_id'         => $threadId,
                    'subject'           => $subject,
                    'from'              => $from,
                    'to'                => $to,
                    'in_reply_to'       => $inReplyTo,
                    'message_id_header' => $messageIdH,
                    'raw_headers'       => $headers->toArray(),
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

            // ── Bridge User + Conversation ELChat ─────────────────────────
            // Email = cas le plus fiable : senderEmail est toujours disponible.
            // Le UserResolver déduplique automatiquement par email.
            $this->bridgeUser(
                account:           $account,
                provider:          'gmail',
                externalUserId:    $senderEmail ?? $from ?? 'unknown',
                displayName:       $senderName,
                email:             $senderEmail,
                phone:             null, // non exposé par Gmail
                conversation:      $conversation,
                socialMessage:     $message,
                isNewConversation: $conversation->wasRecentlyCreated,
                subject:           $subject,
                bodyExcerpt:       $body,
            );

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
                'metadata'               => [
                    'thread_id' => $threadId,
                    'subject'   => $subject,
                    'is_echo'   => true,
                ],
                'published_at' => $publishedAt,
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

        $subject     = $msg['subject']              ?? '(no subject)';
        $from        = $msg['from']['emailAddress'] ?? [];
        $senderEmail = $from['address']             ?? null;
        $senderName  = $from['name']                ?? null;
        $threadId    = $msg['conversationId']       ?? null;
        $body        = strip_tags($msg['body']['content'] ?? '');
        $publishedAt = isset($msg['receivedDateTime'])
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
                'metadata'               => [
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

            // ── Bridge User + Conversation ELChat ─────────────────────────
            $this->bridgeUser(
                account:           $account,
                provider:          'outlook',
                externalUserId:    $senderEmail ?? 'unknown',
                displayName:       $senderName,
                email:             $senderEmail,
                phone:             null, // non exposé par Outlook Graph API
                conversation:      $conversation,
                socialMessage:     $message,
                isNewConversation: $conversation->wasRecentlyCreated,
                subject:           $subject,
                bodyExcerpt:       $body ?: null,
            );

            SocialMessageReceivedJob::dispatch($message->id);
        }

        $this->touchConversation($conversation, $publishedAt);
    }

    // ─────────────────────────────────────────────────────────
    // IMAP
    // ─────────────────────────────────────────────────────────

    public function handleImap(SocialAccount $account, array $emailData): void
    {
        $externalId = "imap:{$account->id}:{$emailData['uid']}";

        if (SocialMessage::where('provider', 'imap')
            ->where('external_message_id', $externalId)
            ->exists()) {
            return;
        }

        $subject     = $emailData['subject']    ?? '(no subject)';
        $senderEmail = $emailData['from_email'] ?? null;
        $senderName  = $emailData['from_name']  ?? null;
        $body        = $emailData['body']        ?? $subject;
        $threadId    = $emailData['message_id'] ?? null;
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
            Log::info('[IMAP][Parser] Nouveau email entrant créé', [
                'message_id'      => $message->id,
                'conversation_id' => $conversation->id,
                'from'            => $senderEmail,
                'subject'         => $subject,
            ]);

            // ── Bridge User + Conversation ELChat ─────────────────────────
            // IMAP peut également exposer le téléphone si le client mail
            // l'a inclus dans la signature (non parsé ici, extensible).
            $this->bridgeUser(
                account:           $account,
                provider:          'imap',
                externalUserId:    $senderEmail ?? 'unknown',
                displayName:       $senderName,
                email:             $senderEmail,
                phone:             $emailData['from_phone'] ?? null, // si ImapSyncService le parse
                conversation:      $conversation,
                socialMessage:     $message,
                isNewConversation: $conversation->wasRecentlyCreated,
                subject:           $subject,
                bodyExcerpt:       is_string($body) ? $body : null,
            );

            SocialMessageReceivedJob::dispatch($message->id);
        }

        $this->touchConversation($conversation, $publishedAt);
    }

    // ─────────────────────────────────────────────────────────
    // BRIDGE USER + CONVERSATION ELCHAT
    // ─────────────────────────────────────────────────────────

    /**
     * Résout le User ELChat et déclenche le bridge Conversation + Message RAG.
     *
     * Email est le canal le plus fiable pour la déduplication :
     *  - senderEmail sert à la fois d'externalUserId ET de clé de déduplication
     *    dans User (le UserResolver cherche d'abord par email si disponible).
     *  - Le contexte LLM est le plus riche : objet + extrait du corps.
     *
     * @param  string  $provider       'gmail' | 'outlook' | 'imap'
     * @param  ?string $bodyExcerpt    Extrait du corps de l'email (tronqué pour le LLM)
     */
    private function bridgeUser(
        SocialAccount     $account,
        string            $provider,
        string            $externalUserId,
        ?string           $displayName,
        ?string           $email,
        ?string           $phone,
        SocialConversation $conversation,
        SocialMessage     $socialMessage,
        bool              $isNewConversation,
        string            $subject,
        ?string           $bodyExcerpt,
    ): void {
        // On ne crée pas de User pour les adresses "unknown"
        if ($externalUserId === 'unknown') {
            return;
        }

        $user = $this->userResolver->resolve(
            account:        $account,
            externalUserId: $externalUserId,
            displayName:    $displayName,
            username:       $email,   // email = identifiant le plus naturel
            email:          $email,
            phone:          $phone,
        );

        if ($isNewConversation) {
            // ── Contexte LLM pour email ────────────────────────────────────
            // L'objet de l'email est l'équivalent du titre de la vidéo YouTube.
            // L'extrait du corps donne le contexte détaillé — on tronque à 300
            // caractères pour ne pas surcharger le LLM.
            $shortBody = $bodyExcerpt
                ? mb_substr(trim(preg_replace('/\s+/', ' ', $bodyExcerpt)), 0, 300)
                : null;

            $this->conversationBridge->bridge(
                socialConv:        $conversation,
                socialMessage:     $socialMessage,
                user:              $user,
                isNewConversation: true,
                videoContext:      [
                    // "title" = objet de l'email → contexte principal pour le LLM
                    'title'       => $subject,
                    // "description" = extrait du corps → contexte enrichi
                    'description' => $shortBody,
                ],
            );
        }
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
        // sinon hash du subject normalisé (sans Re:/Fwd:)
        $contextId = $threadId
            ?? hash('sha256', strtolower(trim(preg_replace('/^(re|fwd?):\s*/i', '', $subject))));

        return SocialConversation::firstOrCreate(
            [
                'social_account_id' => $account->id,
                'provider'          => $account->provider,
                'external_user_id'  => $senderId,
                'context_type'      => 'email_thread',
                'context_id'        => $contextId,
                'context_id_hash'       => hash('sha256', $contextId), // ← ajout
            ],
            [
                'site_id'               => $account->site_id,
                'external_username'     => $senderEmail,
                'external_display_name' => $senderName ?? $senderEmail,
                'context_type'          => 'email_thread',
                'context_id'            => $contextId,
                'context_id_hash'       => hash('sha256', $contextId), // ← ajout
                'source_object_id'      => $contextId,
                'metadata'              => [
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
        // ✅ text/plain en priorité, fallback text/html
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
        // ✅ "Nom Prénom <email@domain.com>" ou "email@domain.com"
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
