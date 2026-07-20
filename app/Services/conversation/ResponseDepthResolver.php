<?php

namespace App\Services\conversation;

use App\Contracts\ResponseDepthResolverInterface;
use App\Enums\ResponseDepth;

final class ResponseDepthResolver implements ResponseDepthResolverInterface
{
    public function resolve(array $signals, int $turnCount): ResponseDepth
    {
        $score = (float) config('conversation_engine.baseline_score', 3.0);

        foreach ($signals as $signal) {
            $score += $signal->weight;
        }

        // Sur le tout premier message, on plafonne : un humain ne déballe pas
        // tout son savoir avant de comprendre le besoin, sauf si la demande
        // explicite (cue fort, ex: "explique-moi en détail") justifie de dépasser.
        if ($turnCount === 0) {
            $progression = config('conversation_engine.progression');
            $explicitPush = $score - (float) config('conversation_engine.baseline_score', 3.0);

            if ($explicitPush < (float) $progression['first_message_cap_override_threshold']) {
                $score = min($score, (float) $progression['first_message_cap']);
            }
        }

        return ResponseDepth::fromScore($score);
    }
}
