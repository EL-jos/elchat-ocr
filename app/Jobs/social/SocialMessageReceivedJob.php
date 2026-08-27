<?php

namespace App\Jobs\social;
use romanzipp\QueueMonitor\Traits\IsMonitored;

use App\Services\Social\SocialReplyEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SocialMessageReceivedJob implements ShouldQueue
{
    use IsMonitored;
    use Dispatchable;
    use Queueable;
    use InteractsWithQueue;

    public function __construct(
        public string $messageId
    ) {}

    public function handle(
        SocialReplyEngine $engine
    ): void {

        Log::info("DANS SocialMessageReceivedJob");
        $engine->process(
            $this->messageId
        );
        Log::info("APRES SocialReplyEngine::process");
    }
}
