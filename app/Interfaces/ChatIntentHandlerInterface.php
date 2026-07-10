<?php

namespace App\Interfaces;

use App\Models\Conversation;
use App\Models\Site;

interface ChatIntentHandlerInterface
{
    /**
     * Handle the user's question for a given site and conversation.
     */
    public function handle(string $question, Site $site, Conversation $conversation): string;
}
