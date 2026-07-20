<?php

namespace App\Enums;

/**
 * Rythme conversationnel : à quel moment de l'échange se trouve-t-on.
 *
 * Distinct de ResponseDepth : la profondeur dit "combien développer",
 * le pace dit "quelle posture adopter" (ouvrir, construire, clarifier...).
 */
enum ConversationPace: string
{
    case Opening    = 'opening';    // premier message, ou reprise après une longue pause
    case Building   = 'building';   // 2e/3e échange, l'intérêt se précise
    case Engaged    = 'engaged';    // conversation avancée, relances multiples sur le même sujet
    case Deepening  = 'deepening';  // l'utilisateur pousse explicitement vers plus de détail

    public function allowsClarifyingQuestion(): bool
    {
        return match ($this) {
            self::Opening, self::Building => true,
            self::Engaged, self::Deepening => false,
        };
    }
}
