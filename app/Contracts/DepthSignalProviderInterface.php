<?php

namespace App\Contracts;

use App\Models\Conversation;
use App\Models\Site;
use App\Services\queryAnalyzer\QueryPlan;
use App\ValueObjects\DepthSignal;

/**
 * Contrat implémenté par chaque source de signal utilisée pour calculer
 * la profondeur de réponse. Ajouter un nouveau signal = ajouter une classe
 * + l'enregistrer dans le binding, sans toucher au ConversationEngine
 * (Open/Closed Principle).
 */
interface DepthSignalProviderInterface
{
    /**
     * @param array $history derniers messages [{role, content}]
     * @return DepthSignal[]
     */
    public function collect(
        QueryPlan $plan,
        Site $site,
        Conversation $conversation,
        string $question,
        array $history,
    ): array;
}
