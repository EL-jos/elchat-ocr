<?php

namespace App\Console\Commands;

use App\Enums\Social\ReplyStatus;
use App\Enums\Social\SocialProvider;
use App\Jobs\social\TelegramReplyJob;
use App\Models\Social\SocialReplyQueue;
use Illuminate\Console\Command;

class TelegramReplyCommand extends Command
{
    protected $signature = 'telegram:test-reply {replyId? : UUID de la SocialReplyQueue à publier}';

    protected $description = 'Teste l\'envoi d\'une réponse Telegram (approuvée ou spécifiée)';

    public function handle(): int
    {
        $replyId = $this->argument('replyId');

        /** @var SocialReplyQueue|null $reply */
        $reply = $replyId
            ? SocialReplyQueue::find($replyId)
            : SocialReplyQueue::query()
                ->whereHas('socialMessage.conversation.socialAccount', fn ($q) =>
                $q->where('provider', SocialProvider::TELEGRAM->value)
                )
                //->where('status', ReplyStatus::APPROVED->value)
                ->latest()
                ->first();

        if (!$reply) {
            $this->error(
                $replyId
                    ? "Aucune SocialReplyQueue trouvée pour l'ID : {$replyId}"
                    : 'Aucune réponse Telegram approuvée trouvée.'
            );
            return self::FAILURE;
        }

        $this->info("→ Envoi de la réponse [{$reply->id}] en mode synchrone...");

        TelegramReplyJob::dispatchSync($reply->id);

        $reply->refresh();

        if ($reply->status === ReplyStatus::PUBLISHED->value) {
            $this->info("✓ Réponse publiée avec succès. (status: {$reply->status})");
            return self::SUCCESS;
        }

        $this->error("✗ Échec. Status: {$reply->status}. Raison: {$reply->failure_reason}");
        return self::FAILURE;
    }
}
