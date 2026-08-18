<?php

namespace App\Domain\Proactive\Delivery\Channels;

use App\Domain\Proactive\Contracts\DeliveryChannel;
use App\Models\Proactive\ProactiveMessage;

class WhatsAppChannel implements DeliveryChannel
{
    public function channel(): string { return 'whatsapp'; }
    public function canSend(ProactiveMessage $message): array { return ['allowed' => false, 'reason' => 'whatsapp_outbound_not_implemented']; }
    public function deliver(ProactiveMessage $message): array { throw new \LogicException('WhatsApp outbound is not implemented.'); }
}
