<?php

namespace App\Domain\MCP\Orchestration;

use App\Services\cta\ChatResponse;

/**
 * Résultat de la passerelle MCP (App\Services\mcp\MCPActionGateService).
 *
 * - not_applicable : le LLM n'a demandé aucun outil → ce n'est pas une
 *   demande d'action, ChatService doit poursuivre son flux RAG normal
 *   (Single/MultiHop) SANS AUCUN CHANGEMENT.
 * - finished : une ou plusieurs actions ont été exécutées, réponse finale
 *   prête à renvoyer directement au visiteur.
 * - awaiting_confirmation : une action nécessite une validation humaine
 *   avant exécution (mode 'confirm' du PermissionEngine).
 */
final readonly class MCPGateResult
{
    private function __construct(
        public string $status,
        public ?ChatResponse $response = null,
        public ?string $pendingConnector = null,
        public ?string $pendingTool = null,
        public ?array $pendingParams = null,
        public ?string $pendingToolCallId = null,
        public ?array $pendingMessages = null,
        public array $trace = [],
    ) {
    }

    public static function notApplicable(): self
    {
        return new self(status: 'not_applicable');
    }

    public static function finished(ChatResponse $response, array $trace = []): self
    {
        return new self(status: 'finished', response: $response, trace: $trace);
    }

    public static function awaitingConfirmation(
        string $connectorSlug,
        string $toolName,
        array $params,
        string $toolCallId,
        array $messages,
        array $trace,
    ): self {
        return new self(
            status: 'awaiting_confirmation',
            pendingConnector: $connectorSlug,
            pendingTool: $toolName,
            pendingParams: $params,
            pendingToolCallId: $toolCallId,
            pendingMessages: $messages,
            trace: $trace,
        );
    }
}
