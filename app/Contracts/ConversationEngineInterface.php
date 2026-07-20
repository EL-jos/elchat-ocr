<?php

namespace App\Contracts;

use App\Models\Conversation;
use App\Models\Site;
use App\Services\queryAnalyzer\QueryPlan;
use App\ValueObjects\ConversationDirective;

interface ConversationEngineInterface
{
    /**
     * Phase 1 — appelée juste après l'analyse de requête, AVANT le retrieval.
     * Ne dépend que du QueryPlan et de l'état de la conversation : peut tourner
     * en parallèle du retrieval sans le bloquer.
     */
    public function decide(
        QueryPlan $plan,
        Site $site,
        Conversation $conversation,
        string $question,
        array $history,
    ): ConversationDirective;

    /**
     * Phase 2 — appelée juste avant le PromptBuilder, une fois le contexte
     * retrouvé connu. Purement déterministe et bon marché (pas d'appel LLM) :
     * ajuste seulement la proposition de question de clarification et, si le
     * contexte est très pauvre, ramène la profondeur vers Short pour éviter
     * de spéculer longuement sur peu d'information.
     *
     * @param float|null $groundingConfidence signal de qualité propre au pipeline
     *        appelant (ex: le score de confiance de MultiHopPipelineServiceV2).
     *        SingleHopPipelineService n'a pas d'équivalent : laisser null, la
     *        politique retombe alors sur retrievedChunkCount.
     */
    public function refine(
        ConversationDirective $directive,
        int $retrievedChunkCount,
        QueryPlan $plan,
        ?float $groundingConfidence = null,
    ): ConversationDirective;
}
