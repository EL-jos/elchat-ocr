<?php

namespace App\Domain\MCP\Exceptions;

class ConfirmationRequiredException extends MCPException
{
    public function __construct(
        public readonly string $connectorSlug,
        public readonly string $toolName,
        public readonly array $params,
        public readonly string $confirmActor, // 🆕 'visitor' | 'admin'
    ) {
        parent::__construct("Confirmation requise ({$confirmActor}) pour {$connectorSlug}.{$toolName}");
    }

    public function errorCode(): string
    {
        return 'confirmation_required';
    }
}
