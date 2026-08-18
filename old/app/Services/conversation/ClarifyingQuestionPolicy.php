<?php

namespace App\Services\conversation;

use App\Contracts\ClarifyingQuestionPolicyInterface;
use App\Enums\ConversationPace;
use App\Services\queryAnalyzer\QueryPlan;

final class ClarifyingQuestionPolicy implements ClarifyingQuestionPolicyInterface
{
    public function shouldOffer(
        QueryPlan $plan,
        ConversationPace $pace,
        int $retrievedChunkCount,
        ?float $groundingConfidence = null,
    ): bool {
        if (!$pace->allowsClarifyingQuestion()) {
            return false;
        }

        $vagueQuery = $plan->queryType === 'exploratory';

        // Pipeline itératif (MultiHop) : on dispose déjà d'un score de
        // confiance cumulé (coverage/quality/diversity), plus fiable qu'un
        // comptage de chunks qui grossit mécaniquement à chaque hop.
        // On réutilise le même seuil (0.4) que MultiHopPipelineServiceV2::shouldStop()
        // utilise pour détecter une stagnation, afin de rester cohérent avec
        // une notion de "contexte pauvre" déjà validée dans ce pipeline.
        if ($groundingConfidence !== null) {
            $threshold = (float) config('conversation_engine.clarifying_question_confidence_threshold', 0.4);

            return $vagueQuery || $groundingConfidence < $threshold;
        }

        // Pipeline en une passe (SingleHop) : le comptage de chunks retenus
        // reflète directement la densité de matière trouvée pour cette question.
        $threshold = (int) config('conversation_engine.clarifying_question_chunk_threshold', 2);
        $thinRetrieval = $retrievedChunkCount > 0 && $retrievedChunkCount <= $threshold;

        return $thinRetrieval || $vagueQuery;
    }
}
