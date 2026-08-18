<?php

namespace App\Services\Social;

use App\Enums\Social\MessageDirection;
use App\Enums\Social\ReplyStatus;
use App\Models\Message;
use App\Models\MessageCTA;
use App\Models\Social\SocialAccount;
use App\Models\Social\SocialMessage;
use App\Models\Social\SocialReplyQueue;
use App\Services\cta\ChatResponse;
use App\Services\ia\ChatService;
use App\Services\MercureService;
use BackedEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SocialReplyEngine
{
    public function __construct(
        protected ChatService $chatService,
        protected SocialConversationMapper $mapper,
        protected MercureService $mercure,   // 👈 AJOUT — injection
        protected SocialReplyDispatcher $dispatcher, // 👈 AJOUT
    ) {}

    public function process(string $messageId): void
    {
        Log::info("DANS SocialReplyEngine::process", ['messageId' => $messageId]);

        $incoming = SocialMessage::findOrFail($messageId);

        if ($incoming->direction->value !== MessageDirection::INCOMING->value) {
            return;
        }

        Log::info("INCOMING SOCIAL REPLY", $incoming->toArray());

        $socialConversation = $incoming->conversation;
        /**
         * @var SocialAccount $socialAccount
         */
        $socialAccount      = $socialConversation->socialAccount;

        // Bridge vers ELChat
        $conversation = $this->mapper->resolveConversation($socialConversation);

        Log::info("RESULTAT DE CONVERSATION MAPPING", $conversation->toArray());

        $site = $conversation->site;

        // Sauvegarde historique ELChat
        $userMessage = Message::create([
            'id'              => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'role'            => 'user',
            'content'         => $incoming->content,
        ]);

        // Mémoire structurée
        $messageCount = $conversation->messages()->count();

        if ($messageCount === 1) {
            $memory = $this->chatService->extractStructuredMemoryFromMessage($userMessage);
            if (!empty($memory)) {
                DB::table('conversation_memories')->updateOrInsert(
                    ['conversation_id' => $conversation->id],
                    [
                        'id'         => (string) Str::uuid(),
                        'memory'     => json_encode($memory),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }

        // Appel RAG
        $chatResponse = $this->chatService->answer(
            site:         $site,
            question:     $incoming->content,
            conversation: $conversation
        );

        Log::info("RESULTAT CHAT", ['chat' => $chatResponse]);

        // Sauvegarde historique ELChat
        $botMessage = Message::create([
            'id'              => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'role'            => 'bot',
            'content'         => $chatResponse->message,
            'entities'        => []/*$chatResponse->entities*/,
        ]);

        foreach ($chatResponse->ctas as $index => $cta) {
            MessageCta::create([
                'id'         => (string) Str::uuid(),
                'message_id' => $botMessage->id,
                'cta_id'     => $cta['id'],
                'position'   => $index,
                'label'      => $cta['label'],
                'action'     => $cta['action'],
                'value'      => $cta['value'] ?? null,
                'style'      => $cta['style'] ?? null,
            ]);
        }

        $messageCount = $conversation->messages()->count();

        if ($messageCount % 5 === 0) {
            $this->chatService->updateConversationMemory($conversation);
        }

        if ($messageCount % 8 === 0) {
            $this->chatService->updateConversationSummary($conversation);
        }

        // Message social sortant
        $outgoing = SocialMessage::create([
            'social_conversation_id' => $socialConversation->id,
            'provider'               => $incoming->provider,
            'direction'              => MessageDirection::OUTGOING->value,
            'content'                => $chatResponse->message,
            'message_type'           => 'text',
            'generated_by_ai'        => true,
            'confidence_score'       => 100,
            'metadata'               => array_merge(
                $incoming->metadata ?? [],
                [
                    'entities' => []/*$chatResponse->entities*/,
                    'ctas'     => []/*$chatResponse->ctas*/,
                ]
            ),
        ]);

        $reply = SocialReplyQueue::create([
            'social_message_id' => $outgoing->id,
            'status'            => ReplyStatus::PENDING->value, // toujours PENDING à la création
        ]);

        // Auto-reply activé → dispatcher immédiatement
        if ($socialAccount->auto_reply) {
            try {
                $this->dispatcher->dispatch($reply); // async, non bloquant
            } catch (Throwable $e) {
                // Déjà loggué dans le dispatcher — on ne bloque pas le process
                Log::error('[SocialReplyEngine] Auto-dispatch échoué', [
                    'reply_id' => $reply->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        // ──────────────────────────────────────────────────────
        // 👈 AJOUT — Publier sur Mercure pour mise à jour RT
        // ──────────────────────────────────────────────────────
        $provider = $incoming->provider instanceof BackedEnum
            ? $incoming->provider->value
            : (string) $incoming->provider;

        $socialAccount->load(['conversations.messages.reply', 'events', 'users', 'site']);

        try {
            $this->mercure->post(
                topic: "site/{$site->id}/integrations",
                data: [
                    'event'           => 'new_message',
                    'socialAccount'  => $socialAccount,
                    'provider'=> $provider,
                ]
            );
        } catch (\Throwable $e) {
            // Mercure ne doit jamais bloquer le traitement
            Log::warning('[SocialReplyEngine] Mercure publish failed', [
                'error'   => $e->getMessage(),
                'site_id' => $site->id,
                'provider'=> $provider,
            ]);
        }
    }
}
