<?php

namespace App\Console\Commands;

use App\Enums\Social\ReplyStatus;
use App\Jobs\social\FacebookReplyJob;
use App\Models\Social\SocialReplyQueue;
use Illuminate\Console\Command;

class SocialTestReplyCommand extends Command
{
    protected $signature =
        'social:test-reply {replyId?}';

    protected $description =
        'Test Facebook publication';

    public function handle(): int
    {
        $replyId = $this->argument('replyId');

        $reply = $replyId
            ? SocialReplyQueue::findOrFail($replyId)
            : SocialReplyQueue::query()
                ->where(
                    'status',
                    ReplyStatus::PENDING->value
                )
                ->latest()
                ->first();

        if (!$reply) {

            $this->error(
                'No approved reply found.'
            );

            return self::FAILURE;
        }

        FacebookReplyJob::dispatchSync(
            $reply->id
        );

        $this->info(
            "Reply published successfully."
        );

        return self::SUCCESS;
    }
}
