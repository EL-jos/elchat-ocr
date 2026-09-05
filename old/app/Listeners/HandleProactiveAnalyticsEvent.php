<?php

namespace App\Listeners;

use App\Domain\Proactive\ProactiveOutcomeService;
use App\Domain\Proactive\ProactiveSequenceService;
use App\Events\AnalyticsEventRecorded;
use App\Models\AnalyticsEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class HandleProactiveAnalyticsEvent implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;
    public array $backoff = [10, 60, 300];

    public function __construct(
        private readonly ProactiveSequenceService $sequences,
        private readonly ProactiveOutcomeService $outcomes,
    ) {}

    public function viaQueue(): string { return config('proactive.queue', 'proactive'); }

    public function handle(AnalyticsEventRecorded $notification): void
    {
        $event = AnalyticsEvent::query()->find($notification->eventId);
        if (!$event || str_starts_with($event->event_type, 'proactive_')) return;

        $this->outcomes->handle($event);
        $this->sequences->evaluateEvent($event);
    }
}
