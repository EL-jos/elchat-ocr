<?php

namespace App\Services\conversation;

use App\Contracts\ReplyPolarityResolverInterface;
use App\Enums\ReplyPolarity;
use App\Services\queryAnalyzer\QueryPlan;

/**
 * Lit le champ `reply_polarity` produit par QueryAnalyzer (extension décrite
 * dans QUERYANALYZER_EXTENSION.md). Ne fait AUCUN appel LLM propre : le
 * champ est déjà calculé au moment où ce resolver est appelé, dans le même
 * appel qui produit clean_query/intent/etc. — coût marginal nul.
 *
 * Ne doit jamais être utilisé seul en premier choix : voir
 * CompositeReplyPolarityResolver, qui privilégie le regex (plus rapide et
 * tout aussi fiable sur les cas évidents) et ne consulte cette source que
 * pour les formulations que le regex ne reconnaît pas.
 */
final class QueryPlanReplyPolarityResolver implements ReplyPolarityResolverInterface
{
    public function resolve(string $question, ?QueryPlan $plan = null): ReplyPolarity
    {
        if (!$plan) {
            return ReplyPolarity::Neutral;
        }

        return match ($plan->replyPolarity ?? 'neutral') {
            'affirmative' => ReplyPolarity::Affirmative,
            'negative' => ReplyPolarity::Negative,
            default => ReplyPolarity::Neutral,
        };
    }
}
