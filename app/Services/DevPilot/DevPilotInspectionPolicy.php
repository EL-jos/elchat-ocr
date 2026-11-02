<?php

namespace App\Services\DevPilot;

/** Shared path exclusions for every read-only DevPilot inspection surface. */
final class DevPilotInspectionPolicy
{
    private const EXCLUDED_SEGMENTS = ['.git', '.svn', '.hg', '.ssh', '.aws', '.config', 'node_modules', 'vendor'];

    public static function isExcludedSegment(string $segment): bool
    {
        return in_array(strtolower($segment), self::EXCLUDED_SEGMENTS, true);
    }

    public static function containsExcludedSegment(string $path): bool
    {
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if (self::isExcludedSegment($segment)) return true;
        }
        return false;
    }

    public static function filterEntries(array $entries): array
    {
        return array_values(array_filter($entries, static function ($entry): bool {
            if (! is_array($entry)) return false;
            $name = (string) ($entry['name'] ?? '');
            $path = (string) ($entry['path'] ?? $name);
            return ! self::containsExcludedSegment($path);
        }));
    }

    public static function filterMatches(array $matches): array
    {
        return array_values(array_filter($matches, static function ($match): bool {
            return is_array($match) && ! self::containsExcludedSegment((string) ($match['path'] ?? ''));
        }));
    }
}
