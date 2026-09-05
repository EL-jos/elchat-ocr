<?php

namespace App\Domain\LLM\Exceptions;

final class LLMRetryAfter
{
    public static function parse(?string $header): ?int
    {
        $value = trim((string) $header);
        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return max(0, (int) $value);
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return max(0, $timestamp - time());
    }
}
