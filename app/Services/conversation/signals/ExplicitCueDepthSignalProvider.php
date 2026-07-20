<?php

namespace App\Services\conversation\signals;

use App\Contracts\DepthSignalProviderInterface;
use App\Models\Conversation;
use App\Models\Site;
use App\Services\queryAnalyzer\QueryPlan;
use App\ValueObjects\DepthSignal;

final class ExplicitCueDepthSignalProvider implements DepthSignalProviderInterface
{
    public function collect(QueryPlan $plan, Site $site, Conversation $conversation, string $question, array $history): array
    {
        $signals = [];

        foreach (config('conversation_engine.explicit_cues', []) as $cue) {
            if (preg_match($cue['pattern'], $question, $matches)) {
                $signals[] = new DepthSignal(
                    'explicit_cue',
                    (float) $cue['weight'],
                    'cue:' . trim($matches[0])
                );
            }
        }

        return $signals;
    }
}
