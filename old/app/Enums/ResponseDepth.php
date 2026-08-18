<?php

namespace App\Enums;

/**
 * Profondeur de réponse calculée par le ConversationEngine.
 *
 * IMPORTANT : cet enum ne doit jamais être exposé au LLM sous forme de règle
 * numérique ("réponds en moins de X mots"). Il sert uniquement à sélectionner :
 *  - des indications qualitatives de style (StyleHintRenderer)
 *  - un budget de tokens technique pour l'appel API (MaxTokensCalculator)
 *
 * La valeur entière associée sert de poids interne pour les calculs
 * (moyenne pondérée, clamp, comparaison), pas pour l'affichage.
 */
enum ResponseDepth: int
{
    case Minimal  = 1; // accusé de réception, confirmation, salutation
    case Short    = 2; // réponse directe à une question simple
    case Normal   = 3; // réponse standard, cas par défaut
    case Detailed = 4; // développement structuré, demande explicite de détail
    case Expert   = 5; // analyse approfondie, demande experte / comparaison complexe

    public static function fromScore(float $score): self
    {
        $rounded = (int) round(max(1, min(5, $score)));

        return self::from($rounded);
    }

    public function label(): string
    {
        return match ($this) {
            self::Minimal  => 'minimal',
            self::Short    => 'short',
            self::Normal   => 'normal',
            self::Detailed => 'detailed',
            self::Expert   => 'expert',
        };
    }
}
