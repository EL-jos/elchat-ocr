<?php

namespace App\Services\conversation;

use App\Contracts\ReplyPolarityResolverInterface;
use App\Enums\ReplyPolarity;
use App\Services\queryAnalyzer\QueryPlan;

/**
 * Combine les deux sources plutôt que de choisir l'une ou l'autre :
 *
 * 1. Regex d'abord — "oui", "non", "ok"... sont non-ambigus, la classification
 *    est instantanée, gratuite, et aussi fiable qu'un LLM sur ces cas précis.
 *    Pas de raison de payer une latence ou un risque d'erreur LLM là où une
 *    règle simple suffit à 100%.
 * 2. QueryPlan (LLM) en repli — uniquement quand le regex ne reconnaît rien
 *    ("mouais", "carrément", "plutôt pas", "pourquoi pas"...). Le champ est
 *    déjà calculé par le même appel LLM que QueryAnalyzer fait de toute façon
 *    à chaque tour pour clean_query/intent — aucun coût marginal.
 *
 * Le regex reste donc le chemin rapide pour le cas dominant en production ;
 * le LLM comble les angles morts du vocabulaire plutôt que de tout reclasser.
 */
final class CompositeReplyPolarityResolver implements ReplyPolarityResolverInterface
{
    public function __construct(
        private readonly RegexReplyPolarityResolver $regexResolver,
        private readonly QueryPlanReplyPolarityResolver $queryPlanResolver,
    ) {
    }

    public function resolve(string $question, ?QueryPlan $plan = null): ReplyPolarity
    {
        $regexResult = $this->regexResolver->resolve($question, $plan);

        if ($regexResult !== ReplyPolarity::Neutral) {
            return $regexResult;
        }

        return $this->queryPlanResolver->resolve($question, $plan);
    }
}
