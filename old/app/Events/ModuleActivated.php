<?php

namespace App\Events;

use App\Models\Payment\Subscription;
use App\Models\Payment\SubscriptionItem;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ModuleActivated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Subscription     $subscription,
        public SubscriptionItem $item,
    ) {}
}
