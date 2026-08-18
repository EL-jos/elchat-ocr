<?php

namespace App\Domain\Proactive\Contracts;

use App\Models\Proactive\ProactiveMessage;

interface DeliveryChannel
{
    public function channel(): string;

    /** @return array{allowed: bool, reason: string|null} */
    public function canSend(ProactiveMessage $message): array;

    /** @return array{accepted: bool, provider: string, external_message_id: string|null, details: array} */
    public function deliver(ProactiveMessage $message): array;
}
