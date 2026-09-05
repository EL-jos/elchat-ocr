<?php

namespace App\Jobs\Middleware;

use App\Services\DocumentLockService;
use Illuminate\Contracts\Cache\LockTimeoutException;

class DocumentOperationLock
{
    public function __construct(private readonly string $documentId)
    {
    }

    public function handle(object $job, callable $next): void
    {
        try {
            app(DocumentLockService::class)->run(
                $this->documentId,
                static fn () => $next($job),
                1,
            );
        } catch (LockTimeoutException) {
            $job->release(10);
        }
    }
}
