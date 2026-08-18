<?php

namespace App\Services\Social;

use App\Enums\Social\ReplyStatus;
use App\Jobs\social\EmailReplyJob;
use App\Jobs\social\FacebookReplyJob;
use App\Jobs\social\TelegramReplyJob;
use App\Jobs\social\YouTubeReplyJob;
use App\Models\Social\SocialReplyQueue;
use BackedEnum;
use Illuminate\Support\Facades\Log;
use Throwable;

class SocialReplyDispatcher
{
    /**
     * Map provider → Job class
     * Ajouter ici pour étendre à un nouveau provider
     */
    private const JOB_MAP = [
        'facebook' => FacebookReplyJob::class,
        'instagram'=> FacebookReplyJob::class, // Instagram via Graph API (même job)
        'telegram' => TelegramReplyJob::class,
        'youtube'  => YouTubeReplyJob::class,
        'gmail'    => EmailReplyJob::class,
        'outlook'  => EmailReplyJob::class,
        'imap'     => EmailReplyJob::class,
    ];

    /**
     * Point d'entrée unique — appelé depuis :
     *   - SocialReplyEngine (auto_reply activé)
     *   - SocialIntegrationController (approbation manuelle)
     *
     * @throws \InvalidArgumentException si provider non supporté
     */
    public function dispatch(SocialReplyQueue $reply): void
    {
        // 1️⃣ Vérifier que la reply est dispatchable
        $this->assertDispatchable($reply);

        // 2️⃣ Résoudre le provider
        $provider = $this->resolveProvider($reply);

        // 3️⃣ Résoudre le Job
        $jobClass = $this->resolveJob($provider);

        // 4️⃣ Marquer comme APPROVED + horodater
        $reply->update([
            'status'      => ReplyStatus::APPROVED->value,
            'approved_at' => now(),
        ]);

        Log::info('[SocialReplyDispatcher] Dispatching reply', [
            'reply_id' => $reply->id,
            'provider' => $provider,
            'job'      => $jobClass,
        ]);

        // 5️⃣ Dispatcher (sync pour tests/approbation manuelle immédiate, async pour auto)
        try {

            Log::info("[SocialReplyDispatcher] Dispatching job {$jobClass}", [
                "reply" => $reply->toArray(),
                "job" => $jobClass,
            ]);

            dispatch(new $jobClass($reply->id)); // toujours async, sans exception

        } catch (Throwable $e) {
            // Si dispatch_sync échoue immédiatement (ex: provider down)
            $reply->update([
                'status'         => ReplyStatus::FAILED->value,
                'failure_reason' => $e->getMessage(),
            ]);

            Log::error('[SocialReplyDispatcher] Dispatch failed', [
                'reply_id' => $reply->id,
                'provider' => $provider,
                'error'    => $e->getMessage(),
            ]);

            throw $e; // Remonter pour que l'appelant gère (controller → 500, engine → log)
        }
    }

    /**
     * Raccourci : approuver ET publier immédiatement (approbation manuelle)
     */
    public function approve(string $replyId): SocialReplyQueue
    {
        $reply = SocialReplyQueue::with([
            'socialMessage.conversation.socialAccount',
        ])->findOrFail($replyId);

        $this->dispatch($reply);

        return $reply->fresh();
    }

    // ─────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────

    private function assertDispatchable(SocialReplyQueue $reply): void
    {
        if (in_array($reply->status, [
            ReplyStatus::PUBLISHED->value,
            ReplyStatus::PROCESSING->value,
        ])) {
            throw new \LogicException(
                "Reply [{$reply->id}] est déjà en statut [{$reply->status}] — dispatch annulé."
            );
        }

        if ($reply->status === ReplyStatus::REJECTED->value) {
            throw new \LogicException(
                "Reply [{$reply->id}] a été rejetée — dispatch annulé."
            );
        }
    }

    private function resolveProvider(SocialReplyQueue $reply): string
    {
        $provider = $reply->socialMessage?->provider;

        if (!$provider) {
            throw new \RuntimeException(
                "Reply [{$reply->id}] : provider introuvable sur le SocialMessage."
            );
        }

        return $provider instanceof BackedEnum
            ? $provider->value
            : (string) $provider;
    }

    private function resolveJob(string $provider): string
    {
        $jobClass = self::JOB_MAP[$provider] ?? null;

        if (!$jobClass) {
            throw new \InvalidArgumentException(
                "Provider [{$provider}] non supporté par SocialReplyDispatcher."
            );
        }

        return $jobClass;
    }
}
