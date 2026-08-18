<?php

namespace App\Enums;

enum ReplyPolarity: string
{
    case Affirmative = 'affirmative'; // "oui", "ok", "vas-y"...
    case Negative     = 'negative';    // "non", "non merci", "pas besoin"...
    case Neutral      = 'neutral';     // tout le reste (pas de polarité détectée)
}
