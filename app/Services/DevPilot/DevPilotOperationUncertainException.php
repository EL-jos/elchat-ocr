<?php

namespace App\Services\DevPilot;

use RuntimeException;
use Throwable;

final class DevPilotOperationUncertainException extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
