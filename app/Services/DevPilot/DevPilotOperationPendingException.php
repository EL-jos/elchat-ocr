<?php

namespace App\Services\DevPilot;

use RuntimeException;

/** A remote operation is known to be queued/running and should be polled later. */
final class DevPilotOperationPendingException extends RuntimeException
{
    public function __construct(public readonly string $operationKey, string $message = 'Remote operation is still running.')
    {
        parent::__construct($message);
    }
}
