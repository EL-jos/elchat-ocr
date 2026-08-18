<?php

namespace App\Contracts;

use App\Enums\ConversationPace;
use App\Services\queryAnalyzer\QueryPlan;

interface ClarifyingQuestionPolicyInterface
{
    /**
     * @param int $retrievedChunkCount nombre de chunks retenus pour le contexte final.
     *        Significatif pour une recherche en une passe (SingleHop). Pour un
     *        pipeline itératif qui accumule des chunks sur plusieurs hops
     *        (MultiHop), ce total cumulé ne mesure pas la même chose qu'un
     *        comptage single-pass — voir $groundingConfidence.
     * @param float|null $groundingConfidence signal de qualité propre au pipeline
     *        appelant quand il existe (ex: le score de confiance cumulé du
     *        MultiHopPipelineServiceV2, déjà calculé par updateState()/shouldStop()).
     *        Quand fourni, prime sur retrievedChunkCount car il reflète la
     *        densité réelle de matière trouvée, pas juste un volume cumulé.
     */
    public function shouldOffer(
        QueryPlan $plan,
        ConversationPace $pace,
        int $retrievedChunkCount,
        ?float $groundingConfidence = null,
    ): bool;
}
