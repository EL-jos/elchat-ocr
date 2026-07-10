<?php

namespace App\Jobs\social;

use App\Enums\Social\ReplyStatus;
use App\Models\Social\SocialEvent;
use App\Models\Social\SocialReplyQueue;
use App\Services\Social\YoutTube\YouTubeEventParser;
use App\SocialChannels\ChannelManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

class YouTubeReplyJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public string $replyQueueId
    ) {}

    public function handle(
        ChannelManager $channels
    ): void {

        DB::transaction(function () use ($channels) {

            $reply = SocialReplyQueue::query()
                ->lockForUpdate()
                ->findOrFail($this->replyQueueId);

            if (
                $reply->status === ReplyStatus::PUBLISHED->value
            ) {
                return;
            }

            if (
                $reply->status !== ReplyStatus::APPROVED->value
            ) {
                return;
            }

            $reply->update([
                'status' => ReplyStatus::PROCESSING->value,
            ]);

            $socialMessage = $reply->socialMessage;

            $account = $socialMessage
                ->conversation
                ->socialAccount;

            $result = $channels->sendReply(
                account: $account,
                message: $socialMessage
            );

            $socialMessage->update([
                'external_message_id'
                => $result['id']
                    ?? null,
            ]);

            $reply->update([
                'status' => ReplyStatus::PUBLISHED->value,
                'published_at' => now(),
            ]);
        });
    }

    public function failed(
        Throwable $e
    ): void {

        $reply = SocialReplyQueue::find(
            $this->replyQueueId
        );

        if (!$reply) {
            return;
        }

        $reply->increment('attempts');

        $reply->update([
            'status' => ReplyStatus::FAILED->value,
            'failure_reason' => $e->getMessage(),
        ]);
    }
}
