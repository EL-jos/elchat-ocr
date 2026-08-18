<?php

namespace App\Services\conversation;

use App\Contracts\ConversationPaceResolverInterface;
use App\Enums\ConversationPace;
use App\Enums\ResponseDepth;
use App\Models\Conversation;
use App\Services\queryAnalyzer\QueryPlan;

final class ConversationPaceResolver implements ConversationPaceResolverInterface
{
    public function resolve(Conversation $conversation, QueryPlan $plan, ResponseDepth $depth, int $turnCount): ConversationPace
    {
        if ($turnCount === 0) {
            return ConversationPace::Opening;
        }

        // Une relance qui pousse explicitement vers l'expert = approfondissement assumé.
        if ($depth === ResponseDepth::Expert || $depth === ResponseDepth::Detailed) {
            return ConversationPace::Deepening;
        }

        return $turnCount <= 2
            ? ConversationPace::Building
            : ConversationPace::Engaged;
    }
}
