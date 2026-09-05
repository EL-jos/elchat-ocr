<?php

namespace App\Jobs\social;

use App\Models\Social\SocialAccount;
use App\Models\Social\SocialConversation;
use App\Models\Social\SocialMessage;
use App\Models\Social\SocialReplyQueue;
use App\SocialChannels\Slack\SlackChannel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SlackReplyJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    public int $backoff = 10;

    public function __construct(
        public string $socialMessageId
    ) {}

    public function handle(SlackChannel $slackChannel): void
    {
        /** @var SocialMessage|null $message */
        $message = SocialMessage::find($this->socialMessageId);

        if (!$message) {
            Log::warning('[Slack][Reply] SocialMessage introuvable', [
                'message_id' => $this->socialMessageId,
            ]);
            return;
        }

        // ✅ Sécurité : ne traiter que les messages sortants générés par l'IA
        if ($message->direction !== 'outgoing' || !$message->generated_by_ai) {
            Log::warning('[Slack][Reply] Message non éligible à l\'envoi', [
                'message_id' => $message->id,
                'direction'  => $message->direction,
            ]);
            return;
        }

        $conversation = SocialConversation::find($message->social_conversation_id);

        if (!$conversation) {
            Log::warning('[Slack][Reply] Conversation introuvable', [
                'message_id' => $message->id,
            ]);
            return;
        }

        $account = SocialAccount::find($conversation->social_account_id);

        if (!$account || !$account->is_active) {
            Log::warning('[Slack][Reply] SocialAccount introuvable ou inactif', [
                'account_id' => $conversation->social_account_id,
            ]);
            return;
        }

        try {

            $result = $slackChannel->sendReply($account, $message);

            // ✅ Slack renvoie le 'ts' réel du message posté — on le
            // stocke pour permettre de futures réponses EN THREAD
            // à CE message (chaînage de la conversation IA <-> user)
            $metadata = $message->metadata ?? [];
            $metadata['slack_response'] = $result;
            $metadata['ts'] = $result['ts'] ?? ($metadata['ts'] ?? null);

            $message->update([
                'metadata' => $metadata,
            ]);

            Log::info('[Slack][Reply] Réponse envoyée avec succès', [
                'message_id' => $message->id,
                'channel_id' => $metadata['channel_id'] ?? null,
            ]);

        } catch (RuntimeException $e) {

            Log::error('[Slack][Reply] Échec de l\'envoi', [
                'message_id' => $message->id,
                'error'      => $e->getMessage(),
            ]);

            throw $e; // ✅ laisse Laravel retenter selon $tries/$backoff
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('[Slack][Reply] Job définitivement échoué', [
            'message_id' => $this->socialMessageId,
            'error'      => $exception->getMessage(),
        ]);

        // Optionnel : marquer le SocialReplyQueue correspondant comme 'failed'
        SocialReplyQueue::where('social_message_id', $this->socialMessageId)
            ->update([
                'status'         => 'failed',
                'failure_reason' => $exception->getMessage(),
            ]);
    }
}
