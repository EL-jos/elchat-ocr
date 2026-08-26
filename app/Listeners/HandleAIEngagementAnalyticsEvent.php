<?php

namespace App\Listeners;

use App\Domain\AIEngagement\AIEngagementService;
use App\Events\AnalyticsEventRecorded;
use App\Models\AnalyticsEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class HandleAIEngagementAnalyticsEvent implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;
    public array $backoff = [10, 60, 300];

    public function __construct(private readonly AIEngagementService $engagement) {}

    public function viaQueue(): string
    {
        return config('proactive.queue', 'proactive');
    }

    public function handle(AnalyticsEventRecorded $notification): void
    {
        $event = AnalyticsEvent::query()->find($notification->eventId);
        if (!$event || $event->source === 'ai_engagement' || str_starts_with($event->event_type, 'engagement_')) return;
        $this->engagement->evaluate($event);
    }
}
