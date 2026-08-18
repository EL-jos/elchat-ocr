<?php

namespace App\Jobs\Proactive;

use App\Domain\Proactive\ProactiveDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendProactiveMessageJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 180;
    public int $uniqueFor = 300;

    public function __construct(public readonly string $messageId) {}
    public function uniqueId(): string { return $this->messageId; }
    public function handle(ProactiveDeliveryService $delivery): void { $delivery->send($this->messageId); }
}
