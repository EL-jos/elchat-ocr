<?php

namespace App\ValueObjects;

use App\Enums\ConversationPace;
use App\Enums\ResponseDepth;

/**
 * Directive conversationnelle : sortie unique et immuable du ConversationEngine.
 *
 * Ne contient AUCUNE règle métier, AUCUN texte définitif : uniquement des
 * décisions (depth, pace, clarifying question) + des fragments de style
 * (styleHints) que le PromptBuilder assemble avec les prompts existants
 * (system prompt, type de site, rôle). Le Conversation Engine ne remplace
 * jamais ces prompts, il les complète.
 */
final class ConversationDirective
{
    /**
     * @param string[] $styleHints petites instructions qualitatives (pas de règles numériques)
     * @param DepthSignal[] $trace signaux ayant mené à la décision, pour logs/debug
     */
    public function __construct(
        public readonly ResponseDepth $depth,
        public readonly ConversationPace $pace,
        public readonly bool $shouldOfferClarifyingQuestion,
        public readonly bool $suppressStockClosings,
        public readonly int $maxTokens,
        public readonly array $styleHints,
        public readonly array $trace = [],
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'depth' => $this->depth->label(),
            'pace' => $this->pace->value,
            'clarifying_question_allowed' => $this->shouldOfferClarifyingQuestion,
            'max_tokens' => $this->maxTokens,
            'trace' => array_map(
                fn (DepthSignal $s) => "{$s->source}:{$s->reason}({$s->weight})",
                $this->trace
            ),
        ];
    }
}
