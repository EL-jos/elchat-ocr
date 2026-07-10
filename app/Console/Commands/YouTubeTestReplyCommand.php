<?php

namespace App\Console\Commands;

use App\Enums\Social\ReplyStatus;
use App\Jobs\social\YouTubeReplyJob;
use App\Models\Social\SocialReplyQueue;
use Illuminate\Console\Command;

class YouTubeTestReplyCommand extends Command
{
    protected $signature = '
        youtube:test-reply
        {replyQueueId : ID de la ligne social_reply_queues}
    ';

    protected $description =
        'Publie immédiatement une réponse YouTube en utilisant YouTubeReplyJob';

    public function handle(): int
    {
        $replyQueueId = $this->argument(
            'replyQueueId'
        );

        $reply = SocialReplyQueue::query()
            ->with([
                'socialMessage.conversation.socialAccount'
            ])
            ->find($replyQueueId);

        if (!$reply) {

            $this->error(
                "ReplyQueue introuvable."
            );

            return self::FAILURE;
        }

        if (
            $reply->socialMessage->provider !== 'youtube'
        ) {

            $this->error(
                "Cette reply queue n'est pas YouTube."
            );

            return self::FAILURE;
        }

        $this->info("ReplyQueue trouvée");

        $this->line(
            "Message ID : {$reply->social_message_id}"
        );

        $this->line(
            "Status actuel : {$reply->status}"
        );

        $this->newLine();

        try {

            /**
             * Simule validation humaine
             */
            if (
                $reply->status === ReplyStatus::PENDING->value
            ) {

                $reply->update([
                    'status'
                    => ReplyStatus::APPROVED->value,
                ]);

                $this->info(
                    'Reply approuvée automatiquement.'
                );
            }

            /**
             * Exécution synchrone
             * (plus simple pour debug)
             */
            dispatch_sync(
                new YouTubeReplyJob(
                    $reply->id
                )
            );

            $reply->refresh();

            $this->newLine();

            $this->info(
                "Publication terminée."
            );

            $this->table(
                ['Champ', 'Valeur'],
                [
                    ['ID', $reply->id],
                    ['Status', $reply->status],
                    ['Published At', $reply->published_at],
                ]
            );

            return self::SUCCESS;

        } catch (Throwable $e) {

            report($e);

            $this->error(
                $e->getMessage()
            );

            return self::FAILURE;
        }
    }
}
