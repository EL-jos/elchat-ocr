<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AnalyticsEventRecorded
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly string $eventId) {}
}
