<?php

namespace App\Console\Commands;

use App\Enums\Social\ReplyStatus;
use App\Jobs\social\EmailReplyJob;
use App\Models\Social\SocialReplyQueue;
use Illuminate\Console\Command;

class EmailReplyCommand extends Command
{
    protected $signature   = 'email:test-reply {replyId? : UUID de la SocialReplyQueue}';
    protected $description = 'Teste l\'envoi d\'une réponse email (Gmail ou IMAP)';

    public function handle(): int
    {
        $replyId = $this->argument('replyId');

        /** @var SocialReplyQueue|null $reply */
        $reply = $replyId
            ? SocialReplyQueue::find($replyId)
            : SocialReplyQueue::query()
                ->whereHas('socialMessage.socialConversation.socialAccount', fn ($q) =>
                $q->whereIn('provider', ['gmail', 'imap'])
                )
                ->where('status', ReplyStatus::APPROVED->value)
                ->latest()
                ->first();

        if (!$reply) {
            $this->error(
                $replyId
                    ? "Aucune SocialReplyQueue trouvée pour l'ID : {$replyId}"
                    : 'Aucune réponse email approuvée trouvée.'
            );
            return self::FAILURE;
        }

        $this->info("→ Envoi de la réponse [{$reply->id}] (provider: "
            . ($reply->socialMessage?->socialConversation?->socialAccount?->provider ?? '?')
            . ") en mode synchrone…"
        );

        EmailReplyJob::dispatchSync($reply->id);

        $reply->refresh();

        if ($reply->status === ReplyStatus::PUBLISHED->value) {
            $this->info("✓ Réponse envoyée avec succès. (status: {$reply->status})");
            return self::SUCCESS;
        }

        $this->error("✗ Échec. Status: {$reply->status}. Raison: {$reply->failure_reason}");
        return self::FAILURE;
    }
}
