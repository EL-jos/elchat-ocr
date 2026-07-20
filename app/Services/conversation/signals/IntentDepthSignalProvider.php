<?php

namespace App\Services\conversation\signals;

use App\Contracts\DepthSignalProviderInterface;
use App\Models\Conversation;
use App\Models\Site;
use App\Services\queryAnalyzer\QueryPlan;
use App\ValueObjects\DepthSignal;

final class IntentDepthSignalProvider implements DepthSignalProviderInterface
{
    public function collect(QueryPlan $plan, Site $site, Conversation $conversation, string $question, array $history): array
    {
        $weights = config('conversation_engine.intent_weights', []);
        $weight = $weights[$plan->intent] ?? 0.0;

        if ($weight === 0.0) {
            return [];
        }

        return [new DepthSignal('intent', $weight, "intent:{$plan->intent}")];
    }
}
