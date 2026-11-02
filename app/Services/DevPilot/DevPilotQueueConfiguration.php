<?php

namespace App\Services\DevPilot;

final class DevPilotQueueConfiguration
{
    public const RETRY_AFTER_MARGIN_SECONDS = 60;

    public static function minimumRetryAfter(int $commandMaximumSeconds): int
    {
        return max(1, $commandMaximumSeconds) + self::RETRY_AFTER_MARGIN_SECONDS;
    }

    public static function hasSufficientRetryAfter(mixed $retryAfter, int $commandMaximumSeconds): bool
    {
        return is_numeric($retryAfter)
            && (int) $retryAfter >= self::minimumRetryAfter($commandMaximumSeconds);
    }
}
