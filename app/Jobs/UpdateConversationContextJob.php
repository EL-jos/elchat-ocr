<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\ia\ChatService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class UpdateConversationContextJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly string $conversationId,
        public readonly bool $updateMemory = false,
        public readonly bool $updateSummary = false,
        public readonly ?string $memoryMessageId = null,
    ) {}

    public function handle(ChatService $chatService): void
    {
        $conversation = Conversation::find($this->conversationId);

        if (!$conversation) {
            return;
        }

        if ($this->updateMemory) {
            if ($this->memoryMessageId !== null) {
                $message = Message::find($this->memoryMessageId);

                if ($message) {
                    $chatService->updateConversationMemoryFromMessage($message);
                }
            } else {
                $chatService->updateConversationMemory($conversation);
            }
        }

        if ($this->updateSummary) {
            $chatService->updateConversationSummary($conversation);
        }
    }

    public function failed(Throwable $exception): void
    {
        report($exception);
    }
}
