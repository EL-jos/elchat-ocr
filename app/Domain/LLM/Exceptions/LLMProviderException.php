<?php

namespace App\Domain\LLM\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

final class LLMProviderException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $operation,
        public readonly string $responseBody = '',
        public readonly ?int $retryAfterSeconds = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct("{$operation} provider returned HTTP {$status}.", 0, $previous);
    }

    public static function fromResponse(Response $response, string $body = '', string $operation = 'LLM'): self
    {
        return new self(
            status: $response->status(),
            operation: $operation,
            responseBody: $body,
            retryAfterSeconds: LLMRetryAfter::parse($response->header('Retry-After')),
        );
    }

    public function bodyExcerpt(int $limit = 1000): string
    {
        return mb_strcut(trim($this->responseBody), 0, max(0, $limit));
    }
}
