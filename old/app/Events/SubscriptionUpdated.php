<?php

namespace App\Events;

use App\Models\Payment\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SubscriptionUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(public Subscription $subscription) {}
}
