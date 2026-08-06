<?php

namespace App\Events;

use App\Models\Payment\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentSucceeded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Subscription $subscription,
        public int          $amountCents,
    ) {}
}
