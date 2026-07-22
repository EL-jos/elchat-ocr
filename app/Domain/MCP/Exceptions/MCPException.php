<?php

namespace App\Domain\MCP\Exceptions;

use Exception;

abstract class MCPException extends Exception
{
    abstract public function errorCode(): string;
}
