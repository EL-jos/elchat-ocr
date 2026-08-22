<?php

namespace App\Listeners;

use App\Enums\AnalyticsEventType;
use App\Events\AnalyticsEventRecorded;
use App\Jobs\VisitorIntelligence\BuildVisitorSessionSummaryJob;
use App\Models\AnalyticsEvent;
use App\Services\VisitorIntelligence\VisitorIntelligenceEventService;
use App\Services\VisitorIntelligence\VisitorIntelligenceRuleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class HandleVisitorIntelligenceEvent implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;
    public array $backoff = [10, 60, 300];

    public function __construct(
        private readonly VisitorIntelligenceEventService $sessions,
        private readonly VisitorIntelligenceRuleService $rules,
    ) {}

    public function viaQueue(): string { return config('analytics.queue', 'analytics'); }

    public function handle(AnalyticsEventRecorded $notification): void
    {
        $event = AnalyticsEvent::query()->find($notification->eventId);
        if (!$event) return;

        $session = $this->sessions->applyRecordedEvent($event);
        if (!$session) return;

        if ($event->source === 'visitor_intelligence') {
            $this->rules->evaluate($event, $session);
        }

        // A visitor journey is deliberately not broadcast event by event.
        // Publishing every browser event made the dashboard display a moving,
        // incomplete replay while the visitor was still browsing. The summary
        // job publishes one session_completed notification once session_end
        // has been processed and the session is safe to read.
        if (in_array($event->event_type, [
            AnalyticsEventType::SESSION_END->value,
            AnalyticsEventType::LEAD_CREATED->value,
            AnalyticsEventType::MEETING_BOOKED->value,
            AnalyticsEventType::CONVERSION->value,
        ], true)) {
            BuildVisitorSessionSummaryJob::dispatch($session->id);
        }
    }
}
