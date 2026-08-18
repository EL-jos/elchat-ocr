<?php

namespace App\Domain\MCP\Exceptions;

/**
 * Le token du site a expiré et ne peut pas être rafraîchi automatiquement.
 * Le site doit se reconnecter via le flux OAuth (statut -> auth_expired).
 */
class AuthExpiredException extends MCPException
{
    public function errorCode(): string
    {
        return 'auth_expired';
    }
}
