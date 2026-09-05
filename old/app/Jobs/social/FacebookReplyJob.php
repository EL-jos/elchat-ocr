<?php

namespace App\Jobs\social;
use romanzipp\QueueMonitor\Traits\IsMonitored;

use App\Enums\Social\ReplyStatus;
use App\Models\Social\SocialReplyQueue;
use App\SocialChannels\ChannelManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class FacebookReplyJob implements ShouldQueue
{
    use IsMonitored;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public string $replyQueueId
    ) {}

    public function handle(
        ChannelManager $channels
    ): void {

        Log::info("DANS FacebookReplyJob AVANT LA TRANSACTION");

        DB::transaction(function () use ($channels) {
            Log::info("DANS FacebookReplyJob DANS LA TRANSACTION");
            $reply = SocialReplyQueue::query()
                ->lockForUpdate()
                ->findOrFail(
                    $this->replyQueueId
                );

            Log::info("REPLY", [
                "id" => $this->replyQueueId,
                "is_published" => $reply->status === ReplyStatus::PUBLISHED->value,
                "is_approved" => $reply->status !== ReplyStatus::APPROVED->value,
                "reply" => $reply->toArray(),
            ]);

            if ( $reply->status === ReplyStatus::PUBLISHED->value) {
                return;
            }

            if ( $reply->status !== ReplyStatus::APPROVED->value ) {
                return;
            }

            $reply->update([
                'status' => ReplyStatus::PROCESSING->value,
            ]);

            $socialMessage = $reply->socialMessage;

            $account = $socialMessage
                    ->conversation
                    ->socialAccount;

            Log::info("PREMIER NIVEAU DE SENDREPLY", [
                "account" => $account->toArray(),
                "message" => $socialMessage->toArray(),
            ]);

            $result = $channels->sendReply(
                account: $account,
                message: $socialMessage
            );

            $socialMessage->update([
                'external_message_id'
                => $result['id'] ?? null,
            ]);

            $reply->update([
                'status' => ReplyStatus::PUBLISHED->value,
                'published_at' => now(),
            ]);
        });
    }

    public function failed( Throwable $e ): void {

        $reply = SocialReplyQueue::find( $this->replyQueueId );

        if (!$reply) {
            return;
        }

        $reply->increment('attempts');

        $reply->update([
            'status' => ReplyStatus::FAILED->value,
            'failure_reason'
            => $e->getMessage(),
        ]);
    }
}
