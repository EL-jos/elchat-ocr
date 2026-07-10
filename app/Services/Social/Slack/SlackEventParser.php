<?php

namespace App\Services\Social\Slack;

use App\Enums\Social\MessageType;
use App\Jobs\social\SocialMessageReceivedJob;
use App\Models\Social\SocialAccount;
use App\Models\Social\SocialConversation;
use App\Models\Social\SocialEvent;
use App\Models\Social\SocialMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SlackEventParser
{
    public function handle(SocialEvent $event): void
    {
        $payload = $event->payload;

        Log::info('[Slack] RAW_PAYLOAD', $payload);

        if (($payload['type'] ?? null) !== 'event_callback') {
            Log::info('[Slack] type ignoré', ['type' => $payload['type'] ?? null]);
            return;
        }

        $slackEvent = $payload['event'] ?? null;

        if (!$slackEvent) {
            Log::warning('[Slack] "event" manquant dans le payload', $payload);
            return;
        }

        $teamId = $payload['team_id'] ?? null;

        if (!$teamId) {
            Log::warning('[Slack] "team_id" manquant', $payload);
            return;
        }

        $account = $this->resolveAccount($teamId);

        if (!$account) {
            return;
        }

        match ($slackEvent['type'] ?? null) {
            'message'     => $this->handleMessage($account, $slackEvent),
            'app_mention' => $this->handleAppMention($account, $slackEvent),
            default       => Log::info('[Slack] event.type ignoré', [
                'type' => $slackEvent['type'] ?? null,
            ]),
        };
    }

    // ─────────────────────────────────────────────────────────
    // RESOLVE ACCOUNT
    // ─────────────────────────────────────────────────────────

    private function resolveAccount(string $teamId): ?SocialAccount
    {
        $account = SocialAccount::where('provider', 'slack')
            ->where('provider_account_id', $teamId)
            ->where('is_active', true)
            ->first();

        if (!$account) {
            Log::warning('[Slack] SocialAccount introuvable ou inactif', [
                'team_id' => $teamId,
            ]);
        }

        return $account;
    }

    // ─────────────────────────────────────────────────────────
    // MESSAGE
    // ─────────────────────────────────────────────────────────

    private function handleMessage(SocialAccount $account, array $slackEvent): void
    {
        // ✅ Ignorer les subtypes non pertinents (message édité,
        // message supprimé, message de jonction de channel, etc.)
        $subtype = $slackEvent['subtype'] ?? null;

        if ($subtype !== null && $subtype !== 'bot_message') {
            Log::info('[Slack][Message] Subtype ignoré', ['subtype' => $subtype]);
            return;
        }

        $channelId = $slackEvent['channel'] ?? null;
        $text      = $slackEvent['text']    ?? null;
        $ts        = $slackEvent['ts']      ?? null;

        if (!$channelId || !$ts) {
            Log::warning('[Slack][Message] Event incomplet', $slackEvent);
            return;
        }

        // ✅ Echo = message envoyé par notre propre Bot
        // (bot_id présent ET correspond au bot_user_id stocké,
        // OU subtype === 'bot_message')
        $botUserId   = $account->metadata['bot_user_id'] ?? null;
        $isOwnBotEcho = $subtype === 'bot_message'
            || ($botUserId && ($slackEvent['user'] ?? $slackEvent['bot_id'] ?? null) === $botUserId);

        if ($isOwnBotEcho) {
            $this->handleEcho($account, $slackEvent, $channelId, $text, $ts);
            return;
        }

        $userId = $slackEvent['user'] ?? null;

        if (!$userId || !$text) {
            Log::warning('[Slack][Message] "user" ou "text" manquant', $slackEvent);
            return;
        }

        $publishedAt = $this->parseSlackTs($ts);

        $conversation = $this->resolveConversation($account, $userId, $channelId);

        // ✅ thread_ts présent ET différent de ts => réponse dans un thread
        $threadTs = $slackEvent['thread_ts'] ?? null;
        $isReply  = $threadTs && $threadTs !== $ts;

        $parentMessage = $isReply
            ? $this->resolveParentMessage($threadTs, $publishedAt)
            : null;

        $message = SocialMessage::firstOrCreate(
            [
                'provider'            => 'slack',
                'external_message_id' => $ts,
            ],
            [
                'social_conversation_id' => $conversation->id,
                'direction'              => 'incoming',
                'content'                => $text,
                'message_type'           => MessageType::TEXT->value,
                'generated_by_ai'        => false,
                'metadata' => [
                    'raw'               => $slackEvent,
                    'channel_id'        => $channelId,
                    'ts'                => $ts,
                    'thread_ts'         => $threadTs,
                    'parent_message_id' => $parentMessage?->id,
                    'is_reply'          => $isReply,
                ],
                'published_at' => $publishedAt,
            ]
        );

        if ($message->wasRecentlyCreated) {
            Log::info('[Slack][Message] Nouveau message entrant créé', [
                'message_id'      => $message->id,
                'conversation_id' => $conversation->id,
                'channel_id'      => $channelId,
                'is_reply'        => $isReply,
            ]);

            SocialMessageReceivedJob::dispatch($message->id);
        }

        $this->touchConversation($conversation, $publishedAt);
    }

    // ─────────────────────────────────────────────────────────
    // APP MENTION (@ELChat mentionné dans un channel)
    //
    // Slack envoie SOUVENT 'message' ET 'app_mention' pour le même
    // event quand le Bot est mentionné. On traite uniquement
    // 'app_mention' pour les actions spécifiques (ex: déclenchement
    // explicite de l'IA), le message lui-même étant déjà capturé
    // via handleMessage(). Ici on se contente de logger / marquer.
    // ─────────────────────────────────────────────────────────

    private function handleAppMention(SocialAccount $account, array $slackEvent): void
    {
        $ts = $slackEvent['ts'] ?? null;

        if (!$ts) {
            return;
        }

        // ✅ Le message correspondant a déjà été créé via handleMessage()
        // (Slack envoie les deux events). On marque juste qu'il s'agit
        // d'une mention explicite, utile pour prioriser le traitement IA.
        $message = SocialMessage::where('provider', 'slack')
            ->where('external_message_id', $ts)
            ->first();

        if (!$message) {
            Log::info('[Slack][AppMention] Message correspondant pas encore créé, ignoré', [
                'ts' => $ts,
            ]);
            return;
        }

        $metadata = $message->metadata ?? [];
        $metadata['is_app_mention'] = true;

        $message->update(['metadata' => $metadata]);

        Log::info('[Slack][AppMention] Message marqué comme mention explicite', [
            'message_id' => $message->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────
    // ECHO (réponse du Bot via SlackChannel::sendReply())
    // ─────────────────────────────────────────────────────────

    private function handleEcho(
        SocialAccount $account,
        array         $slackEvent,
        string        $channelId,
        ?string       $text,
        string        $ts,
    ): void {

        if (!$text) {
            Log::info('[Slack][Message] Echo Bot sans texte ignoré', ['ts' => $ts]);
            return;
        }

        $threadTs = $slackEvent['thread_ts'] ?? $ts;

        // ✅ Retrouver la conv via le thread (le Bot répond toujours
        // dans le thread d'un message utilisateur existant)
        $rootMessage = SocialMessage::where('provider', 'slack')
            ->where('external_message_id', $threadTs)
            ->first();

        if (!$rootMessage) {
            Log::warning('[Slack][Message] Echo Bot sans message racine trouvé', [
                'thread_ts' => $threadTs,
            ]);
            return;
        }

        $conversation = SocialConversation::find($rootMessage->social_conversation_id);

        if (!$conversation) {
            Log::warning('[Slack][Message] Echo Bot sans conversation trouvée', [
                'conversation_id' => $rootMessage->social_conversation_id,
            ]);
            return;
        }

        $publishedAt = $this->parseSlackTs($ts);

        $parentMessage = $this->resolveParentMessage($threadTs, $publishedAt);

        $message = SocialMessage::firstOrCreate(
            [
                'provider'            => 'slack',
                'external_message_id' => $ts,
            ],
            [
                'social_conversation_id' => $conversation->id,
                'direction'              => 'outgoing',
                'content'                => $text,
                'message_type'           => MessageType::TEXT->value,
                'generated_by_ai'        => true,
                'metadata' => [
                    'raw'               => $slackEvent,
                    'channel_id'        => $channelId,
                    'ts'                => $ts,
                    'thread_ts'         => $threadTs,
                    'parent_message_id' => $parentMessage?->id,
                    'is_echo'           => true,
                ],
                'published_at' => $publishedAt,
            ]
        );

        if ($message->wasRecentlyCreated) {
            Log::info('[Slack][Message] Echo Bot enregistré comme message sortant', [
                'message_id'      => $message->id,
                'conversation_id' => $conversation->id,
            ]);
        }

        $this->touchConversation($conversation, $publishedAt);
    }

    // ─────────────────────────────────────────────────────────
    // RESOLVE CONVERSATION — 1 conv par (user + channel)
    // ─────────────────────────────────────────────────────────

    private function resolveConversation(
        SocialAccount $account,
        string        $userId,
        string        $channelId,
    ): SocialConversation {

        return SocialConversation::firstOrCreate(
            [
                'social_account_id' => $account->id,
                'provider'          => 'slack',
                'external_user_id'  => $userId,
                'context_type'      => 'channel_message',
                'context_id'        => $channelId,
            ],
            [
                'site_id'               => $account->site_id,
                'external_username'     => null, // enrichissable via users.info si besoin
                'external_display_name' => null,
                'context_type'          => 'channel_message',
                'context_id'            => $channelId,
                'source_object_id'      => $channelId,
                'metadata' => [
                    'user_id'    => $userId,
                    'channel_id' => $channelId,
                ],
                'last_message_at' => now(),
            ]
        );
    }

    // ─────────────────────────────────────────────────────────
    // RESOLVE PARENT MESSAGE
    //
    // Contrairement à Facebook/YouTube/Instagram, Slack NE PLATIT
    // PAS les threads : thread_ts pointe toujours vers le message
    // racine exact, et toutes les réponses du même thread partagent
    // le même thread_ts (pas d'aplatissement à corriger).
    //
    // Le "parent logique" reste donc le message racine lui-même
    // (pas le dernier message du thread), car Slack n'a qu'UN seul
    // niveau de threading (pas de réponses de réponses imbriquées).
    // ─────────────────────────────────────────────────────────

    private function resolveParentMessage(string $threadTs, Carbon $currentPublishedAt): ?SocialMessage
    {
        return SocialMessage::where('provider', 'slack')
            ->where('external_message_id', $threadTs)
            ->first();
    }

    // ─────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────

    /**
     * Slack ts format: "1718123456.000200" (secondes.microsecondes)
     */
    private function parseSlackTs(string $ts): Carbon
    {
        return Carbon::createFromTimestamp((float) $ts);
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
