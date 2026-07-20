<?php

namespace App\Contracts;

use App\Enums\ConversationPace;
use App\Enums\ResponseDepth;
use App\Models\Conversation;
use App\Services\queryAnalyzer\QueryPlan;

interface ConversationPaceResolverInterface
{
    public function resolve(
        Conversation $conversation,
        QueryPlan $plan,
        ResponseDepth $depth,
        int $turnCount,
    ): ConversationPace;
}
