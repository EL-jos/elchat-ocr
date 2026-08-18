<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Cache;

class DocumentLockService
{
    public function run(string $documentId, Closure $operation, int $waitSeconds = 5): mixed
    {
        return Cache::lock($this->key($documentId), 3600)
            ->block($waitSeconds, $operation);
    }

    private function key(string $documentId): string
    {
        return "document-lifecycle:{$documentId}";
    }
}
