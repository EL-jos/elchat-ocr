<?php

namespace App\Jobs\social;

use App\Enums\Social\ReplyStatus;
use App\Enums\Social\SocialProvider;
use App\Models\Social\SocialAccount;
use App\Models\Social\SocialMessage;
use App\Models\Social\SocialReplyQueue;
use App\SocialChannels\Telegram\TelegramChannel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramReplyJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Nombre max de tentatives.
     * On ne retente PAS à l'infini : une réponse IA envoyée
     * deux fois serait visible par l'utilisateur final.
     */
    public int $tries = 3;

    /**
     * Backoff exponentiel entre les tentatives (en secondes).
     */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public string $replyId
    ) {}

    public function handle(TelegramChannel $channel): void
    {
        /** @var SocialReplyQueue|null $reply */
        $reply = SocialReplyQueue::find($this->replyId);

        if (!$reply) {
            Log::warning('[Telegram][ReplyJob] SocialReplyQueue introuvable', [
                'reply_id' => $this->replyId,
            ]);
            return;
        }

        // ✅ Idempotence : si déjà publié ou en cours, on ignore
        if (!in_array($reply->status, [
            ReplyStatus::APPROVED->value,
            ReplyStatus::PENDING->value,
        ])) {
            Log::info('[Telegram][ReplyJob] Réponse ignorée (statut non éligible)', [
                'reply_id' => $this->replyId,
                'status'   => $reply->status,
            ]);
            return;
        }

        $reply->update([
            'status'   => ReplyStatus::PROCESSING->value,
            'attempts' => ($reply->attempts ?? 0) + 1,
        ]);

        /** @var SocialMessage|null $message */
        $message = SocialMessage::find($reply->social_message_id);

        if (!$message) {
            $reply->update([
                'status'         => ReplyStatus::FAILED->value,
                'failure_reason' => 'SocialMessage introuvable.',
            ]);
            return;
        }

        $conversation = $message->conversation;

        Log::info('[Telegram][ReplyJob] Conversation Message', [
            'conversation' => $conversation,
        ]);

        if (!$conversation) {
            $reply->update([
                'status'         => ReplyStatus::FAILED->value,
                'failure_reason' => 'SocialConversation introuvable.',
            ]);
            return;
        }

        /** @var SocialAccount|null $account */
        $account = SocialAccount::where('id', $conversation->social_account_id)
            ->where('provider', SocialProvider::TELEGRAM->value)
            ->where('is_active', true)
            ->first();

        if (!$account) {
            $reply->update([
                'status'         => ReplyStatus::FAILED->value,
                'failure_reason' => 'SocialAccount Telegram introuvable ou inactif.',
            ]);
            return;
        }

        try {

            $result = $channel->sendReply($account, $message);

            $reply->update([
                'status'       => ReplyStatus::PUBLISHED->value,
                'published_at' => now(),
            ]);

            // ✅ Marquer le message original comme ayant reçu une réponse
            $message->update([
                'metadata' => array_merge($message->metadata ?? [], [
                    'replied_at'        => now()->toIso8601String(),
                    'telegram_reply_id' => $result['message_id'] ?? null,
                ]),
            ]);

            Log::info('[Telegram][ReplyJob] Réponse publiée avec succès', [
                'reply_id'          => $this->replyId,
                'account_id'        => $account->id,
                'telegram_reply_id' => $result['message_id'] ?? null,
            ]);

        } catch (Throwable $e) {

            $isFinal = $this->attempts() >= $this->tries;

            $reply->update([
                'status'         => $isFinal
                    ? ReplyStatus::FAILED->value
                    : ReplyStatus::PENDING->value,
                'failure_reason' => $e->getMessage(),
            ]);

            Log::error('[Telegram][ReplyJob] Échec envoi réponse', [
                'reply_id'   => $this->replyId,
                'attempt'    => $this->attempts(),
                'is_final'   => $isFinal,
                'error'      => $e->getMessage(),
            ]);

            throw $e; // ✅ Laravel gère le retry via $backoff
        }
    }

    public function failed(Throwable $exception): void
    {
        $reply = SocialReplyQueue::find($this->replyId);

        $reply?->update([
            'status'         => ReplyStatus::FAILED->value,
            'failure_reason' => $exception->getMessage(),
        ]);

        Log::error('[Telegram][ReplyJob] Définitivement échoué', [
            'reply_id' => $this->replyId,
            'error'    => $exception->getMessage(),
        ]);
    }
}
