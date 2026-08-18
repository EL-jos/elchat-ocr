<?php

namespace App\Console\Commands;

use App\Enums\Social\ReplyStatus;
use App\Jobs\social\SlackReplyJob;
use App\Models\Social\SocialReplyQueue;
use Illuminate\Console\Command;
use Throwable;

class SlackTestReplyCommand extends Command
{
    protected $signature = '
        slack:test-reply
        {replyQueueId? : ID de la ligne social_reply_queues (optionnel — prend la plus récente si omis)}
    ';

    protected $description =
        'Publie immédiatement une réponse Slack en utilisant SlackReplyJob';

    public function handle(): int
    {
        $replyQueueId = $this->argument('replyQueueId');

        $reply = $replyQueueId
            ? $this->findById($replyQueueId)
            : $this->findLatestSlackReply();

        if (!$reply) {

            $this->error(
                $replyQueueId
                    ? "ReplyQueue introuvable."
                    : "Aucune ReplyQueue Slack en attente trouvée."
            );

            return self::FAILURE;
        }

        if ($reply->socialMessage->provider !== 'slack') {

            $this->error("Cette reply queue n'est pas Slack.");

            return self::FAILURE;
        }

        $this->info("ReplyQueue trouvée");

        $this->line("ReplyQueue ID  : {$reply->id}");
        $this->line("Message ID     : {$reply->social_message_id}");
        $this->line("Status actuel  : {$reply->status}");
        $this->line("Contenu        : {$reply->socialMessage->content}");

        $this->newLine();

        try {

            /**
             * Simule validation humaine
             */
            if ($reply->status === ReplyStatus::PENDING->value) {

                $reply->update([
                    'status' => ReplyStatus::APPROVED->value,
                ]);

                $this->info('Reply approuvée automatiquement.');
            }

            /**
             * Exécution synchrone
             * (plus simple pour debug)
             */
            dispatch_sync(
                new SlackReplyJob($reply->social_message_id)
            );

            $reply->refresh();

            $this->newLine();
            $this->info("Publication terminée.");

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

            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function findById(string $replyQueueId): ?SocialReplyQueue
    {
        return SocialReplyQueue::query()
            ->with(['socialMessage.conversation.socialAccount'])
            ->find($replyQueueId);
    }

    /**
     * ✅ Sans ID fourni : prend la reply Slack la plus récente,
     * en priorisant 'pending' puis 'approved' (déjà validée mais
     * pas encore publiée — utile pour rejouer un échec).
     */
    private function findLatestSlackReply(): ?SocialReplyQueue
    {
        return SocialReplyQueue::query()
            ->with(['socialMessage.conversation.socialAccount'])
            ->whereHas('socialMessage', function ($q) {
                $q->where('provider', 'slack');
            })
            ->whereIn('status', [
                ReplyStatus::PENDING->value,
                ReplyStatus::APPROVED->value,
            ])
            ->orderByDesc('created_at')
            ->first();
    }
}
