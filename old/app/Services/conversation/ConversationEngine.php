<?php

namespace App\Services\conversation;

use App\Contracts\ClarifyingQuestionPolicyInterface;
use App\Contracts\ConversationEngineInterface;
use App\Contracts\ConversationPaceResolverInterface;
use App\Contracts\MaxTokensCalculatorInterface;
use App\Contracts\ResponseDepthResolverInterface;
use App\Contracts\StyleHintRendererInterface;
use App\Models\AIRole;
use App\Models\Conversation;
use App\Models\Site;
use App\Services\queryAnalyzer\QueryPlan;
use App\ValueObjects\ConversationDirective;

final class ConversationEngine implements ConversationEngineInterface
{
    /**
     * @param iterable<\App\Contracts\DepthSignalProviderInterface> $signalProviders
     *        Injecté via un binding taggé (voir AppServiceProvider) : ajouter un
     *        nouveau signal ne nécessite pas de modifier cette classe (Open/Closed).
     */
    public function __construct(
        private readonly iterable $signalProviders,
        private readonly ResponseDepthResolverInterface $depthResolver,
        private readonly ConversationPaceResolverInterface $paceResolver,
        private readonly ClarifyingQuestionPolicyInterface $clarifyingQuestionPolicy,
        private readonly StyleHintRendererInterface $styleHintRenderer,
        private readonly MaxTokensCalculatorInterface $maxTokensCalculator,
    ) {
    }

    public function decide(QueryPlan $plan, Site $site, Conversation $conversation, string $question, array $history): ConversationDirective
    {
        $turnCount = collect($history)->where('role', 'assistant')->count();

        $signals = [];
        foreach ($this->signalProviders as $provider) {
            $signals = [...$signals, ...$provider->collect($plan, $site, $conversation, $question, $history)];
        }

        $depth = $this->depthResolver->resolve($signals, $turnCount);
        $pace = $this->paceResolver->resolve($conversation, $plan, $depth, $turnCount);

        // Décision préliminaire : pas encore de compte de chunks retrouvés,
        // affinée ensuite par refine().
        $justDeclinedElaboration = $this->hasSignal($signals, 'declines:offer_to_elaborate');

        $shouldOfferClarifyingQuestion = !$justDeclinedElaboration
            && $pace->allowsClarifyingQuestion()
            && $plan->queryType === 'exploratory';

        $suppressStockClosings = $turnCount > 0; // dès la 2e réponse, on évite les formules automatiques

        $role = $site->settings?->aiRole ?? AIRole::default()->first();

        $styleHints = $this->styleHintRenderer->render(
            $depth,
            $pace,
            $shouldOfferClarifyingQuestion,
            $suppressStockClosings,
            $site,
            $role?->name,
        );

        if ($justDeclinedElaboration) {
            $styleHints[] = "l'utilisateur vient de décliner ta proposition d'approfondir : accuse réception brièvement, sans insister ni reproposer immédiatement la même chose";
        }

        return new ConversationDirective(
            depth: $depth,
            pace: $pace,
            shouldOfferClarifyingQuestion: $shouldOfferClarifyingQuestion,
            suppressStockClosings: $suppressStockClosings,
            maxTokens: $this->maxTokensCalculator->calculate($depth),
            styleHints: $styleHints,
            trace: $signals,
        );
    }

    public function refine(
        ConversationDirective $directive,
        int $retrievedChunkCount,
        QueryPlan $plan,
        ?float $groundingConfidence = null,
    ): ConversationDirective {
        $shouldOfferClarifyingQuestion = $this->clarifyingQuestionPolicy->shouldOffer(
            $plan,
            $directive->pace,
            $retrievedChunkCount,
            $groundingConfidence,
        );

        // Contexte pauvre : on ne pousse pas une profondeur élevée sur du vide.
        // Priorité au signal de confiance quand il existe (MultiHop), sinon
        // repli sur le comptage de chunks (SingleHop) — voir ClarifyingQuestionPolicy
        // pour la raison de cette distinction.
        $depth = $directive->depth;
        $poorGrounding = $groundingConfidence !== null
            ? $groundingConfidence < (float) config('conversation_engine.clarifying_question_confidence_threshold', 0.4)
            : ($retrievedChunkCount > 0 && $retrievedChunkCount <= 1);

        if ($poorGrounding && $depth->value > \App\Enums\ResponseDepth::Short->value) {
            $depth = \App\Enums\ResponseDepth::Short;
        }

        if ($shouldOfferClarifyingQuestion === $directive->shouldOfferClarifyingQuestion && $depth === $directive->depth) {
            return $directive;
        }

        return new ConversationDirective(
            depth: $depth,
            pace: $directive->pace,
            shouldOfferClarifyingQuestion: $shouldOfferClarifyingQuestion,
            suppressStockClosings: $directive->suppressStockClosings,
            maxTokens: $this->maxTokensCalculator->calculate($depth),
            styleHints: $directive->styleHints,
            trace: $directive->trace,
        );
    }

    /**
     * @param \App\ValueObjects\DepthSignal[] $signals
     */
    private function hasSignal(array $signals, string $reason): bool
    {
        foreach ($signals as $signal) {
            if ($signal->reason === $reason) {
                return true;
            }
        }

        return false;
    }
}
