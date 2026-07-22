<?php

namespace App\Domain\MCP\Exceptions;

/**
 * L'API tierce est injoignable (timeout, 5xx, DNS...). Distincte d'une
 * erreur métier (ex: commande introuvable), qui elle est un ToolResult::fail
 * normal et non une exception.
 */
class ConnectorUnavailableException extends MCPException
{
    public function errorCode(): string
    {
        return 'connector_unavailable';
    }
}
