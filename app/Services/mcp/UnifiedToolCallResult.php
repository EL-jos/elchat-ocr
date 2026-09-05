<?php

namespace App\Services\mcp;

use App\Models\Mcp\McpPendingAction;

/**
 * Résultat du passage de génération unifié texte + outils.
 *
 * Le statut text signifie que le modèle n'a appelé aucun outil : ChatService
 * doit alors reprendre sa validation RAG habituelle. Les autres statuts sont
 * des réponses finales et ne doivent pas être envoyés au validateur RAG.
 */
final readonly class UnifiedToolCallResult
{
    public const TEXT = 'text';

    public const HANDLED = 'handled';

    public const CLARIFICATION = 'clarification';

    public const AWAITING_CONFIRMATION = 'awaiting_confirmation';

    public const FAILED = 'failed';

    public const FAILED_AFTER_TOOL = 'failed_after_tool';

    public function __construct(
        public string $status,
        public ?string $message = null,
        public array $suggestedActions = [],
        public ?McpPendingAction $pendingAction = null,
        public array $trace = [],
    ) {}
}
