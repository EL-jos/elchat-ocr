<?php

namespace App\Domain\MCP\Exceptions;

/**
 * L'action demandée nécessite une validation humaine (mode 'confirm').
 * L'orchestrateur intercepte cette exception pour mettre la conversation en
 * pause et proposer un bouton de confirmation côté frontend, au lieu
 * d'exécuter l'action.
 */
class ConfirmationRequiredException extends MCPException
{
    public function __construct(
        public readonly string $connectorSlug,
        public readonly string $toolName,
        public readonly array $params,
    ) {
        parent::__construct("Confirmation requise pour {$connectorSlug}.{$toolName}");
    }

    public function errorCode(): string
    {
        return 'confirmation_required';
    }
}
