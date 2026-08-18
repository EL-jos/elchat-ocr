<?php

namespace App\Domain\MCP\Exceptions;

class PermissionDeniedException extends MCPException
{
    public function errorCode(): string
    {
        return 'permission_denied';
    }
}
