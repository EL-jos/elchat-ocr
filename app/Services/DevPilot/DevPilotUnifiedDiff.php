<?php

namespace App\Services\DevPilot;

/** Builds a bounded, dependency-free unified diff for approval previews. */
final class DevPilotUnifiedDiff
{
    private const CONTEXT_LINES = 3;
    private const MAX_EDIT_DISTANCE = 128;

    public static function generate(string $path, string $before, string $after): string
    {
        if ($before === $after) return '';

        $oldLines = self::lines($before);
        $newLines = self::lines($after);
        $operations = self::shortestEditScript($oldLines, $newLines);
        $changedIndexes = [];
        foreach ($operations as $index => $operation) {
            if ($operation[0] !== ' ') $changedIndexes[] = $index;
        }
        if ($changedIndexes === []) return '';

        $hunks = self::hunks($operations, $changedIndexes);
        $diff = "--- a/{$path}\n+++ b/{$path}\n";
        foreach ($hunks as [$start, $end]) {
            $oldBefore = 0;
            $newBefore = 0;
            for ($index = 0; $index < $start; $index++) {
                if ($operations[$index][0] !== '+') $oldBefore++;
                if ($operations[$index][0] !== '-') $newBefore++;
            }

            $oldCount = 0;
            $newCount = 0;
            for ($index = $start; $index <= $end; $index++) {
                if ($operations[$index][0] !== '+') $oldCount++;
                if ($operations[$index][0] !== '-') $newCount++;
            }
            $oldStart = $oldCount === 0 ? $oldBefore : $oldBefore + 1;
            $newStart = $newCount === 0 ? $newBefore : $newBefore + 1;
            $diff .= "@@ -{$oldStart},{$oldCount} +{$newStart},{$newCount} @@\n";

            for ($index = $start; $index <= $end; $index++) {
                [$type, $line] = $operations[$index];
                $diff .= $type.$line;
                if (! str_ends_with($line, "\n")) $diff .= "\n\\ No newline at end of file\n";
            }
        }

        return $diff;
    }

    /** @return list<string> Lines retain their original line endings. */
    private static function lines(string $content): array
    {
        if ($content === '') return [];

        return preg_split('/(?<=\n)/', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** @return list<array{0: string, 1: string}> */
    private static function shortestEditScript(array $old, array $new): array
    {
        $oldCount = count($old);
        $newCount = count($new);
        $maximum = $oldCount + $newCount;
        $frontier = [1 => 0];
        $trace = [];

        for ($distance = 0; $distance <= $maximum; $distance++) {
            if ($distance > self::MAX_EDIT_DISTANCE) return self::replacementScript($old, $new);
            $trace[$distance] = $frontier;

            for ($diagonal = -$distance; $diagonal <= $distance; $diagonal += 2) {
                $goDown = $diagonal === -$distance
                    || ($diagonal !== $distance && ($frontier[$diagonal - 1] ?? -1) < ($frontier[$diagonal + 1] ?? -1));
                $x = $goDown ? ($frontier[$diagonal + 1] ?? 0) : (($frontier[$diagonal - 1] ?? 0) + 1);
                $y = $x - $diagonal;

                while ($x < $oldCount && $y < $newCount && $old[$x] === $new[$y]) {
                    $x++;
                    $y++;
                }
                $frontier[$diagonal] = $x;

                if ($x >= $oldCount && $y >= $newCount) {
                    return self::backtrack($trace, $distance, $old, $new, $x, $y);
                }
            }
        }

        return self::replacementScript($old, $new);
    }

    /** @return list<array{0: string, 1: string}> */
    private static function backtrack(array $trace, int $distance, array $old, array $new, int $x, int $y): array
    {
        $operations = [];
        for ($step = $distance; $step > 0; $step--) {
            $frontier = $trace[$step];
            $diagonal = $x - $y;
            $goDown = $diagonal === -$step
                || ($diagonal !== $step && ($frontier[$diagonal - 1] ?? -1) < ($frontier[$diagonal + 1] ?? -1));
            $previousDiagonal = $goDown ? $diagonal + 1 : $diagonal - 1;
            $previousX = $frontier[$previousDiagonal] ?? 0;
            $previousY = $previousX - $previousDiagonal;

            while ($x > $previousX && $y > $previousY) {
                $operations[] = [' ', $old[$x - 1]];
                $x--;
                $y--;
            }
            if ($x === $previousX) {
                $operations[] = ['+', $new[$y - 1]];
                $y--;
            } else {
                $operations[] = ['-', $old[$x - 1]];
                $x--;
            }
        }

        while ($x > 0 && $y > 0) {
            $operations[] = [' ', $old[$x - 1]];
            $x--;
            $y--;
        }
        while ($x > 0) $operations[] = ['-', $old[--$x]];
        while ($y > 0) $operations[] = ['+', $new[--$y]];

        return array_reverse($operations);
    }

    /** @return list<array{0: string, 1: string}> */
    private static function replacementScript(array $old, array $new): array
    {
        $operations = [];
        foreach ($old as $line) $operations[] = ['-', $line];
        foreach ($new as $line) $operations[] = ['+', $line];

        return $operations;
    }

    /** @return list<array{0: int, 1: int}> */
    private static function hunks(array $operations, array $changedIndexes): array
    {
        $hunks = [];
        foreach ($changedIndexes as $changedIndex) {
            $start = $changedIndex;
            $end = $changedIndex;
            $context = 0;
            for ($index = $changedIndex - 1; $index >= 0 && $context < self::CONTEXT_LINES; $index--) {
                $start = $index;
                if ($operations[$index][0] === ' ') $context++;
            }
            $context = 0;
            for ($index = $changedIndex + 1, $count = count($operations); $index < $count && $context < self::CONTEXT_LINES; $index++) {
                $end = $index;
                if ($operations[$index][0] === ' ') $context++;
            }

            $last = count($hunks) - 1;
            if ($last >= 0 && $start <= $hunks[$last][1]) {
                $hunks[$last][1] = max($hunks[$last][1], $end);
            } else {
                $hunks[] = [$start, $end];
            }
        }

        return $hunks;
    }
}
