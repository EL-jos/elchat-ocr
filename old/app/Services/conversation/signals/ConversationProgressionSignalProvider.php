<?php

namespace App\Services\conversation\signals;

use App\Contracts\DepthSignalProviderInterface;
use App\Models\Conversation;
use App\Models\Site;
use App\Services\queryAnalyzer\QueryPlan;
use App\ValueObjects\DepthSignal;

final class ConversationProgressionSignalProvider implements DepthSignalProviderInterface
{
    public function collect(QueryPlan $plan, Site $site, Conversation $conversation, string $question, array $history): array
    {
        // Nombre de tours déjà échangés = messages assistant dans le fenêtre d'historique.
        $turnCount = collect($history)->where('role', 'assistant')->count();

        if ($turnCount === 0) {
            return []; // premier message : géré par le cap dédié dans ResponseDepthResolver
        }

        $config = config('conversation_engine.progression');
        $weight = min(
            $config['max_followup_bonus'],
            $turnCount * $config['per_followup_weight']
        );

        return [new DepthSignal('progression', $weight, "turn:{$turnCount}")];
    }
}
