<?php

namespace App\ValueObjects;

/**
 * Un signal unitaire contribuant au calcul de ResponseDepth.
 * Immutable, purement descriptif : sert au calcul ET au debug/log
 * (on peut tracer pourquoi une profondeur a été choisie).
 */
final class DepthSignal
{
    public function __construct(
        public readonly string $source,   // ex: "intent", "explicit_cue", "role", "site_type", "progression"
        public readonly float $weight,    // delta appliqué au score de base (peut être négatif)
        public readonly string $reason,   // ex: "cue:étape par étape", "intent:comparison"
    ) {
    }
}
