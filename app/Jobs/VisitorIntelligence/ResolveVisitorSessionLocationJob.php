<?php

namespace App\Jobs\VisitorIntelligence;

use App\Models\VisitorSession;
use App\Services\VisitorIntelligence\VisitorSessionLocationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class ResolveVisitorSessionLocationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public array $backoff = [30];

    public function __construct(
        public readonly string $sessionId,
        public readonly string $encryptedIp,
    ) {
        $queue = trim((string) config('visitor-intelligence.geo.queue'));
        if ($queue !== '') {
            $this->onQueue($queue);
        }
    }

    public function handle(VisitorSessionLocationService $locations): void
    {
        $locations->resolve($this->sessionId, Crypt::decryptString($this->encryptedIp));
    }

    public function failed(?Throwable $exception): void
    {
        VisitorSession::query()->whereKey($this->sessionId)->update([
            'location_status' => 'failed',
            'location_resolved_at' => now(),
        ]);
    }
}
