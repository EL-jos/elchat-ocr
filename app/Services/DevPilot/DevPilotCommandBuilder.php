<?php

namespace App\Services\DevPilot;

use RuntimeException;

/** Builds safely quoted commands for the already-verified remote POSIX shell. */
final class DevPilotCommandBuilder
{
    public static function forInteractivePty(string $workingDirectory, array $arguments, bool $privateUmask = false): string
    {
        self::validateArguments($arguments);
        return 'cd '.self::quotePosix($workingDirectory).' && '.($privateUmask ? 'umask 077 && ' : '')
            .implode(' ', array_map(static fn (string $argument): string => self::quotePosix($argument), $arguments));
    }

    /** Render the exact argv tuple for a human-readable, non-executable preview. */
    public static function displayArgv(array $arguments): string
    {
        self::validateArguments($arguments);
        return implode(' ', array_map(static fn (string $argument): string => self::quotePosix($argument), $arguments));
    }

    private static function validateArguments(array $arguments): void
    {
        if ($arguments === [] || ! is_string($arguments[0]) || $arguments[0] === '' || str_starts_with($arguments[0], '-')) {
            throw new RuntimeException('The approved command has no safe executable.');
        }
        foreach ($arguments as $argument) {
            if (! is_string($argument) || str_contains($argument, "\0") || preg_match('/[\x01-\x1F\x7F]/', $argument) === 1) {
                throw new RuntimeException('The approved command arguments are malformed.');
            }
        }
    }

    private static function quotePosix(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }
}
