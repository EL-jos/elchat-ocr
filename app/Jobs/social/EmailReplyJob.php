<?php

namespace App\Jobs\social;
use romanzipp\QueueMonitor\Traits\IsMonitored;

use App\Enums\Social\ReplyStatus;
use App\Models\Social\SocialAccount;
use App\Models\Social\SocialMessage;
use App\Models\Social\SocialReplyQueue;
use App\SocialChannels\ChannelManager;
use App\SocialChannels\Email\GmailChannel;
use App\SocialChannels\Email\ImapChannel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class EmailReplyJob implements ShouldQueue
{
    use IsMonitored;
    use Dispatchable, InteractsWithQueue, Queueable;

    public int   $tries   = 3;
    public array $backoff = [30, 90, 300];

    public function __construct(public string $replyId) {}

    public function handle(GmailChannel $gmail, ImapChannel $imap, ChannelManager $channels): void
    {
        /** @var SocialReplyQueue|null $reply */
        $reply = SocialReplyQueue::find($this->replyId);

        if (!$reply) {
            Log::warning('[Email][ReplyJob] SocialReplyQueue introuvable', [
                'reply_id' => $this->replyId,
            ]);
            return;
        }

        if (!in_array($reply->status, [
            ReplyStatus::APPROVED->value,
            ReplyStatus::PENDING->value,
        ])) {
            return;
        }

        $reply->update([
            'status'   => ReplyStatus::PROCESSING->value,
            'attempts' => ($reply->attempts ?? 0) + 1,
        ]);

        $message = SocialMessage::find($reply->social_message_id);

        if (!$message) {
            $reply->update([
                'status'         => ReplyStatus::FAILED->value,
                'failure_reason' => 'SocialMessage introuvable.',
            ]);
            return;
        }

        $conversation = $message->conversation;

        Log::info("[Email][ReplyJob] SocialReplyQueue introuvable", [
            'reply_id' => $this->replyId,
            'message_id' => $message->conversation,
            'conversation' => $conversation,
        ]);

        if (!$conversation) {
            $reply->update([
                'status'         => ReplyStatus::FAILED->value,
                'failure_reason' => 'SocialConversation introuvable.',
            ]);
            return;
        }

        $account = SocialAccount::where('id', $conversation->social_account_id)
            ->whereIn('provider', ['gmail', 'imap'])
            ->where('is_active', true)
            ->first();

        if (!$account) {
            $reply->update([
                'status'         => ReplyStatus::FAILED->value,
                'failure_reason' => 'SocialAccount email introuvable ou inactif.',
            ]);
            return;
        }

        Log::info("[Email][ReplyJob] PROVIDER", [
            'conversation provider' => $conversation->provider,
            'account provider' => $account->provider,
        ]);

        try {

            $result = $channels->sendReply(
                account: $account,
                message: $message
            );

            /*// ✅ Router vers le bon channel selon le provider
            $channel = match ($account->provider) {
                'gmail' => $gmail,
                'imap'  => $imap,
                default => throw new RuntimeException(
                    "Provider email non supporté : {$account->provider}"
                ),
            };

            $result = $channel->sendReply($account, $message);*/

            $reply->update([
                'status'       => ReplyStatus::PUBLISHED->value,
                'published_at' => now(),
            ]);

            $message->update([
                'metadata' => array_merge($message->metadata ?? [], [
                    'replied_at' => now()->toIso8601String(),
                    'reply_result' => $result,
                ]),
            ]);

            Log::info('[Email][ReplyJob] Réponse publiée', [
                'reply_id'   => $this->replyId,
                'account_id' => $account->id,
                'provider'   => $account->provider,
            ]);

        } catch (Throwable $e) {

            $isFinal = $this->attempts() >= $this->tries;

            $reply->update([
                'status'         => $isFinal
                    ? ReplyStatus::FAILED->value
                    : ReplyStatus::PENDING->value,
                'failure_reason' => $e->getMessage(),
            ]);

            Log::error('[Email][ReplyJob] Échec envoi', [
                'reply_id'  => $this->replyId,
                'attempt'   => $this->attempts(),
                'is_final'  => $isFinal,
                'error'     => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        $reply = SocialReplyQueue::find($this->replyId);
        $reply?->update([
            'status'         => ReplyStatus::FAILED->value,
            'failure_reason' => $exception->getMessage(),
        ]);

        Log::error('[Email][ReplyJob] Définitivement échoué', [
            'reply_id' => $this->replyId,
            'error'    => $exception->getMessage(),
        ]);
    }
}
