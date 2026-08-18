<?php

namespace App\Services\conversation\signals;

use App\Contracts\DepthSignalProviderInterface;
use App\Contracts\ReplyPolarityResolverInterface;
use App\Enums\ReplyPolarity;
use App\Models\Conversation;
use App\Models\Site;
use App\Services\queryAnalyzer\QueryPlan;
use App\ValueObjects\DepthSignal;

/**
 * Détecte une réponse courte (affirmative OU négative) qui répond à un
 * message assistant se terminant par une question/proposition d'approfondir
 * (ex: "Souhaitez-vous en savoir plus ?"). Symétrique par construction :
 *  - affirmative → pousse la profondeur vers le haut (l'utilisateur a
 *    explicitement accepté qu'on développe)
 *  - négative → pousse la profondeur vers le bas (l'utilisateur a
 *    explicitement décliné, développer quand même serait contre-productif)
 *
 * La classification elle-même est déléguée à ReplyPolarityResolverInterface :
 * cette classe ne sait pas COMMENT on détecte "oui"/"non", seulement quoi en
 * faire dans le calcul de profondeur (séparation des responsabilités).
 */
final class ShortReplyPolarityProvider implements DepthSignalProviderInterface
{
    public function __construct(
        private readonly ReplyPolarityResolverInterface $polarityResolver,
    ) {
    }

    public function collect(QueryPlan $plan, Site $site, Conversation $conversation, string $question, array $history): array
    {
        $lastAssistantMessage = collect($history)->where('role', 'assistant')->last();
        $lastContent = $lastAssistantMessage['content'] ?? '';

        // On ne classifie la polarité que dans un contexte où elle a un sens :
        // le message précédent proposait explicitement d'aller plus loin.
        if (!str_ends_with(trim($lastContent), '?')) {
            return [];
        }

        $polarity = $this->polarityResolver->resolve($question, $plan);

        if ($polarity === ReplyPolarity::Neutral) {
            return [];
        }

        $config = config('conversation_engine.reply_polarity');

        return match ($polarity) {
            ReplyPolarity::Affirmative => [new DepthSignal(
                'reply_polarity',
                (float) ($config['affirmative_weight'] ?? 1.5),
                'accepts:offer_to_elaborate'
            )],
            ReplyPolarity::Negative => [new DepthSignal(
                'reply_polarity',
                (float) ($config['negative_weight'] ?? -1.5),
                'declines:offer_to_elaborate'
            )],
            default => [],
        };
    }
}
