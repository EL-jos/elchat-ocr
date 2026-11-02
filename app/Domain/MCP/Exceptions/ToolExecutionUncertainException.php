<?php

namespace App\Domain\MCP\Exceptions;

use RuntimeException;

/** A connector call started but its transport ended without a reliable result. */
final class ToolExecutionUncertainException extends RuntimeException
{
}
