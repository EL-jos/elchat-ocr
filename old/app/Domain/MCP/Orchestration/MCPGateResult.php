<?php

namespace App\Domain\MCP\Orchestration;

use App\Models\Mcp\McpPendingAction;
use App\Services\cta\ChatResponse;

/**
 * - not_applicable : pas une action -> ChatService poursuit son flux RAG normal.
 * - finished : réponse finale prête.
 * - awaiting_confirmation : une mcp_pending_actions a été créée ; le visiteur
 *   OU un agent back-office doit la résoudre (voir McpPendingAction::confirm_actor).
 */
final readonly class MCPGateResult
{
    private function __construct(
        public string $status,
        public ?ChatResponse $response = null,
        public ?McpPendingAction $pendingAction = null,
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

    public static function awaitingConfirmation(McpPendingAction $pendingAction, array $trace): self
    {
        return new self(status: 'awaiting_confirmation', pendingAction: $pendingAction, trace: $trace);
    }
}
