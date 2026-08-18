<?php

namespace App\Services\conversation;

use App\Contracts\ReplyPolarityResolverInterface;
use App\Enums\ReplyPolarity;
use App\Services\queryAnalyzer\QueryPlan;

/**
 * Classification déterministe par regex, entièrement pilotée par
 * config/conversation_engine.php (reply_polarity.*). Ignore volontairement
 * $plan : c'est le rôle de CompositeReplyPolarityResolver de décider quand
 * basculer vers une source plus riche (voir QueryPlanReplyPolarityResolver).
 */
final class RegexReplyPolarityResolver implements ReplyPolarityResolverInterface
{
    public function resolve(string $question, ?QueryPlan $plan = null): ReplyPolarity
    {
        $config = config('conversation_engine.reply_polarity');

        foreach ($config['negative_patterns'] ?? [] as $pattern) {
            if (preg_match($pattern, $question)) {
                return ReplyPolarity::Negative;
            }
        }

        foreach ($config['affirmative_patterns'] ?? [] as $pattern) {
            if (preg_match($pattern, $question)) {
                return ReplyPolarity::Affirmative;
            }
        }

        return ReplyPolarity::Neutral;
    }
}
