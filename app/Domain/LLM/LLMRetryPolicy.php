<?php

namespace App\Domain\LLM;

use App\Domain\LLM\Exceptions\LLMProviderException;
use Illuminate\Http\Client\ConnectionException;
use JsonException;
use Throwable;

final class LLMRetryPolicy
{
    public static function isRetryableStatus(?int $status): bool
    {
        return $status === 429 || ($status !== null && $status >= 500 && $status <= 599);
    }

    public static function isRetryableException(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof LLMProviderException) {
                return self::isRetryableStatus($current->status);
            }

            // A timeout is surfaced by Laravel's HTTP client as a
            // ConnectionException. Invalid JSON can be caused by a truncated
            // provider/proxy response and is safe to retry as well.
            if ($current instanceof ConnectionException || $current instanceof JsonException) {
                return true;
            }
        }

        return false;
    }

    public static function retryAfterSeconds(Throwable $exception): ?int
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof LLMProviderException) {
                return $current->retryAfterSeconds;
            }
        }

        return null;
    }

    public static function delayMilliseconds(int $attempt, ?int $retryAfterSeconds = null): int
    {
        $base = max(0, (int) config('llm.provider.retry_base_delay_ms', 200));
        $maximum = max($base, (int) config('llm.provider.retry_max_delay_ms', 5000));

        if ($retryAfterSeconds !== null) {
            // Respect the provider's signal, but never block a worker longer
            // than the configured safety cap.
            return min($maximum, max(0, $retryAfterSeconds * 1000));
        }

        $exponent = max(0, min(10, $attempt - 1));
        $delay = min($maximum, $base * (2 ** $exponent));
        $jitterPercent = max(0, min(100, (int) config('llm.provider.retry_jitter_percent', 20)));

        if ($delay === 0 || $jitterPercent === 0) {
            return (int) $delay;
        }

        $spread = (int) round($delay * ($jitterPercent / 100));
        if ($spread === 0) {
            return (int) $delay;
        }

        try {
            return random_int(max(0, (int) $delay - $spread), min($maximum, (int) $delay + $spread));
        } catch (Throwable) {
            // Randomness is an optimization, never a reason to fail an LLM
            // request.
            return (int) $delay;
        }
    }
}
