<?php

namespace App\Domain\MCP\Exceptions;

class ToolNotFoundException extends MCPException
{
    public function errorCode(): string
    {
        return 'tool_not_found';
    }
}
