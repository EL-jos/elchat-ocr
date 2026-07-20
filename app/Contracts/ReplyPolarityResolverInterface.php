<?php

namespace App\Contracts;

use App\Enums\ReplyPolarity;
use App\Services\queryAnalyzer\QueryPlan;

/**
 * Classifie la polarité d'un texte court (affirmatif / négatif / neutre).
 *
 * @param string $question texte à classifier (le texte brut tapé par
 *        l'utilisateur — voir ShortReplyPolarityProvider pour pourquoi).
 * @param QueryPlan|null $plan optionnel : permet à une implémentation
 *        informée par LLM de lire un champ déjà calculé par ailleurs
 *        (ex: QueryAnalyzer::reply_polarity, ajouté à son contrat JSON
 *        existant — zéro appel LLM supplémentaire). Une implémentation
 *        purement déterministe (regex) peut l'ignorer.
 */
interface ReplyPolarityResolverInterface
{
    public function resolve(string $question, ?QueryPlan $plan = null): ReplyPolarity;
}
