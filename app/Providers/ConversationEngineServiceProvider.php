<?php

namespace App\Providers;

use App\Contracts\ClarifyingQuestionPolicyInterface;
use App\Contracts\ConversationEngineInterface;
use App\Contracts\ConversationPaceResolverInterface;
use App\Contracts\MaxTokensCalculatorInterface;
use App\Contracts\ReplyPolarityResolverInterface;
use App\Contracts\ResponseDepthResolverInterface;
use App\Contracts\StyleHintRendererInterface;
use App\Services\conversation\ClarifyingQuestionPolicy;
use App\Services\conversation\CompositeReplyPolarityResolver;
use App\Services\conversation\ConversationEngine;
use App\Services\conversation\ConversationPaceResolver;
use App\Services\conversation\MaxTokensCalculator;
use App\Services\conversation\QueryPlanReplyPolarityResolver;
use App\Services\conversation\RegexReplyPolarityResolver;
use App\Services\conversation\ResponseDepthResolver;
use App\Services\conversation\signals\ConversationProgressionSignalProvider;
use App\Services\conversation\signals\ExplicitCueDepthSignalProvider;
use App\Services\conversation\signals\IntentDepthSignalProvider;
use App\Services\conversation\signals\QuestionComplexitySignalProvider;
use App\Services\conversation\signals\RoleDepthSignalProvider;
use App\Services\conversation\signals\ShortReplyPolarityProvider;
use App\Services\conversation\signals\SiteTypeDepthSignalProvider;
use App\Services\conversation\StyleHintRenderer;
use Illuminate\Support\ServiceProvider;

class ConversationEngineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/conversation_engine.php', 'conversation_engine');

        $this->app->bind(ResponseDepthResolverInterface::class, ResponseDepthResolver::class);
        $this->app->bind(ConversationPaceResolverInterface::class, ConversationPaceResolver::class);
        $this->app->bind(ClarifyingQuestionPolicyInterface::class, ClarifyingQuestionPolicy::class);
        $this->app->bind(StyleHintRendererInterface::class, StyleHintRenderer::class);
        $this->app->bind(MaxTokensCalculatorInterface::class, MaxTokensCalculator::class);

        // Détection de polarité ("oui"/"non") : regex d'abord (rapide,
        // fiable sur le vocabulaire courant), repli sur le champ
        // reply_polarity de QueryAnalyzer pour les formulations que le
        // regex ne reconnaît pas (voir CompositeReplyPolarityResolver et
        // QUERYANALYZER_EXTENSION.md).
        $this->app->bind(ReplyPolarityResolverInterface::class, function ($app) {
            return new CompositeReplyPolarityResolver(
                regexResolver: $app->make(RegexReplyPolarityResolver::class),
                queryPlanResolver: $app->make(QueryPlanReplyPolarityResolver::class),
            );
        });

        // Registre des sources de signal. Pour ajouter un nouveau signal :
        // créer la classe (implémente DepthSignalProviderInterface) et
        // l'ajouter à cette liste — aucune autre modification requise.
        $this->app->bind('conversation.signal_providers', function ($app) {
            return [
                $app->make(IntentDepthSignalProvider::class),
                $app->make(ExplicitCueDepthSignalProvider::class),
                $app->make(ShortReplyPolarityProvider::class),
                $app->make(RoleDepthSignalProvider::class),
                $app->make(SiteTypeDepthSignalProvider::class),
                $app->make(ConversationProgressionSignalProvider::class),
                $app->make(QuestionComplexitySignalProvider::class),
            ];
        });

        $this->app->bind(ConversationEngineInterface::class, function ($app) {
            return new ConversationEngine(
                signalProviders: $app->make('conversation.signal_providers'),
                depthResolver: $app->make(ResponseDepthResolverInterface::class),
                paceResolver: $app->make(ConversationPaceResolverInterface::class),
                clarifyingQuestionPolicy: $app->make(ClarifyingQuestionPolicyInterface::class),
                styleHintRenderer: $app->make(StyleHintRendererInterface::class),
                maxTokensCalculator: $app->make(MaxTokensCalculatorInterface::class),
            );
        });
    }
}
